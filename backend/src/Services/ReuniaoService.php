<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\AgendaLockInterface;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\ReuniaoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Presenter;
use Sigep\Support\Tempo;

/**
 * Reuniões são COMPROMISSOS REAIS: têm início e término, ocupam a agenda dos participantes e não podem
 * sobrepor atendimentos nem outras reuniões deles (AgendaService). Cancelar libera o horário e mantém o histórico.
 * Só a Instituição convida orientadores; o orientador convida os demais participantes permitidos.
 */
final class ReuniaoService
{
    public function __construct(
        private ReuniaoRepositoryInterface $repo,
        private UsuarioRepositoryInterface $users,
        private AgendaService $agenda,
        private AgendaLockInterface $lock,
        private NotificationService $notif,
        private AuditService $audit
    ) {
    }

    public function listar(array $ator, array $q): array
    {
        $rows = $ator['perfil'] === Perfil::ADMIN ? $this->repo->all() : $this->repo->paraUsuario($ator['id']);
        $apr = array_map(fn ($r) => $this->apresentar($r), $rows);
        if (!empty($q['situacao'])) {
            $apr = array_filter($apr, fn ($r) => $r['situacao'] === $q['situacao']);
        }
        usort($apr, fn ($a, $b) => strcmp($a['data'] . $a['horario'], $b['data'] . $b['horario']));
        return array_values($apr);
    }

    public function obter(array $ator, string $id): array
    {
        return $this->apresentar($this->carregar($ator, $id));
    }

    public function criar(array $ator, array $in): array
    {
        return $this->lock->run(function () use ($ator, $in) {
            $d = $this->validar($ator, $in);
            $this->exigirNaoPassado($d['data'], $d['horarioFim']);
            $this->agenda->exigirDiaSemAtendimentos($d['data']);
            $this->exigirParticipantesLivres($ator['id'], $d, null);
            $r = $this->repo->create($d + ['status' => 'Agendada', 'criadoPor' => $ator['id'], 'criadoEm' => date('c')]);
            $this->audit->log($ator, "Cadastrou a reunião {$r['id']} \"{$r['assunto']}\"", Categoria::REUNIOES);
            $this->notif->notificar($r['participantes'], "Você foi convidado(a) para a reunião \"{$r['assunto']}\" em " . date('d/m/Y', strtotime($r['data'])) . " das {$r['horario']} às {$r['horarioFim']}.", Categoria::REUNIOES, $ator['id'], ['tipo' => 'reuniao', 'id' => $r['id']]);
            return $this->apresentar($r);
        });
    }

    public function atualizar(array $ator, string $id, array $in): array
    {
        return $this->lock->run(function () use ($ator, $id, $in) {
            $r = $this->doCriadorOuAdmin($ator, $id);
            if ($this->situacao($r) !== 'Agendada') {
                throw HttpException::conflict('Só reuniões agendadas (que ainda não terminaram) podem ser editadas.');
            }
            $d = $this->validar($ator, $in);
            $this->exigirNaoPassado($d['data'], $d['horarioFim']);
            if ($d['data'] !== $r['data']) { $this->agenda->exigirDiaSemAtendimentos($d['data']); }
            $this->exigirParticipantesLivres($r['criadoPor'], $d, $id);
            $novo = $this->repo->update($id, $d);
            $this->audit->log($ator, "Alterou a reunião {$id} \"{$novo['assunto']}\"", Categoria::REUNIOES);
            $this->notif->notificar($novo['participantes'], "A reunião \"{$novo['assunto']}\" foi alterada.", Categoria::REUNIOES, $ator['id'], ['tipo' => 'reuniao', 'id' => $id]);
            return $this->apresentar($novo);
        });
    }

    /** Cancelar (ou "excluir") libera a agenda dos participantes e NÃO apaga o registro: ele continua no histórico como Cancelada. */
    public function cancelar(array $ator, string $id): array
    {
        return $this->lock->run(function () use ($ator, $id) {
            $r = $this->doCriadorOuAdmin($ator, $id);
            if ($this->situacao($r) !== 'Agendada') {
                throw HttpException::conflict('Só reuniões agendadas (que ainda não terminaram) podem ser canceladas.');
            }
            $novo = $this->repo->update($id, ['status' => 'Cancelada', 'canceladaEm' => date('c'), 'canceladaPor' => $ator['id']]);
            $this->audit->log($ator, "Cancelou a reunião {$id} \"{$novo['assunto']}\"", Categoria::REUNIOES);
            $this->notif->notificar($novo['participantes'], "A reunião \"{$novo['assunto']}\" foi cancelada.", Categoria::REUNIOES, $ator['id'], ['tipo' => 'reuniao', 'id' => $id]);
            return $this->apresentar($novo);
        });
    }

    public function situacao(array $r): string
    {
        if (($r['status'] ?? '') === 'Cancelada') {
            return 'Cancelada';
        }
        return Tempo::passou($r['data'], $r['horarioFim'] ?? $r['horario']) ? 'Realizada' : 'Agendada';
    }

    // ---------- internos ----------
    private function carregar(array $ator, string $id): array
    {
        $r = $this->repo->find($id);
        $ve = $r && ($ator['perfil'] === Perfil::ADMIN || $r['criadoPor'] === $ator['id'] || in_array($ator['id'], $r['participantes'], true));
        if (!$ve) {
            throw HttpException::notFound('Reunião não encontrada.');
        }
        return $r;
    }

    private function doCriadorOuAdmin(array $ator, string $id): array
    {
        $r = $this->carregar($ator, $id);
        if ($ator['perfil'] !== Perfil::ADMIN && $r['criadoPor'] !== $ator['id']) {
            throw HttpException::forbidden('Só quem criou a reunião (ou a Instituição) pode alterá-la.');
        }
        return $r;
    }

    private function validar(array $ator, array $in): array
    {
        $d = Validator::validate($in, [
            'assunto' => 'required|string|min:3|max:160', 'descricao' => 'nullable|string|max:1000',
            'data' => 'required|date', 'horario' => 'required|time', 'horarioFim' => 'required|time', 'participantes' => 'required|array|min:1',
        ]);
        $d['horario'] = Tempo::hhmm($d['horario']);
        $d['horarioFim'] = Tempo::hhmm($d['horarioFim']);
        $d['descricao'] = $d['descricao'] ?? '';
        if (Tempo::minutos($d['horarioFim']) <= Tempo::minutos($d['horario'])) {
            throw HttpException::unprocessable(['horarioFim' => 'O horário de término deve ser depois do início.']);
        }
        $ids = array_values(array_unique(array_map('strval', $d['participantes'])));
        foreach ($ids as $pid) {
            $u = $this->users->find($pid);
            if (!$u || $u['status'] !== 'Ativo') {
                throw HttpException::unprocessable(['participantes' => "Participante inválido ou inativo: $pid"]);
            }
            if ($ator['perfil'] === Perfil::ORIENTADOR && $u['perfil'] === Perfil::ORIENTADOR && $pid !== $ator['id']) {
                throw HttpException::forbidden('Somente a Instituição pode adicionar outros orientadores a uma reunião.');
            }
        }
        $d['participantes'] = $ids;
        return $d;
    }

    /** Todos os envolvidos (participantes + quem criou) precisam estar livres no intervalo COMPLETO da reunião. */
    private function exigirParticipantesLivres(string $criadoPor, array $d, ?string $ignorarReuniao): void
    {
        $ini = Tempo::minutos($d['horario']);
        $fim = Tempo::minutos($d['horarioFim']);
        foreach (array_values(array_unique(array_merge([$criadoPor], $d['participantes']))) as $pessoa) {
            $this->agenda->exigirPessoaLivre($pessoa, $d['data'], $ini, $fim, null, $ignorarReuniao);
        }
    }

    private function exigirNaoPassado(string $data, string $horario): void
    {
        if ($data < Tempo::hoje() || ($data === Tempo::hoje() && Tempo::passou($data, $horario))) {
            throw HttpException::unprocessable(['data' => 'A data e o horário não podem estar no passado.']);
        }
    }

    private function apresentar(array $r): array
    {
        $r['horarioFim'] = $r['horarioFim'] ?? $r['horario'];
        $r['situacao'] = $this->situacao($r);
        $r['participantesDetalhe'] = array_values(array_filter(array_map(function ($id) {
            $u = $this->users->find($id);
            return $u ? Presenter::basico($u) : null;
        }, $r['participantes'])));
        return $r;
    }
}
