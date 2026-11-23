<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\AgendaLockInterface;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\DisponibilidadeRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Tempo;

/**
 * RF03 — disponibilidade do orientador: períodos por dia da semana, com minutos quebrados (08:17–11:43)
 * e até 5 intervalos por período (almoço, pausas). Períodos e intervalos nunca se sobrepõem.
 * Esta é a referência dos horários oferecidos ao responsável (AgendaService calcula os horários livres).
 */
final class DisponibilidadeService
{
    public const MAX_INTERVALOS = 5;
    private const DIAS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

    public function __construct(
        private DisponibilidadeRepositoryInterface $repo,
        private UsuarioRepositoryInterface $users,
        private AgendaService $agenda,
        private AgendaLockInterface $lock,
        private AuditService $audit
    ) {
    }

    public function listar(array $ator, ?string $orientadorId): array
    {
        if ($ator['perfil'] === Perfil::ORIENTADOR) {
            $orientadorId = $ator['id']; // orientador só gerencia a própria agenda
        }
        if (!$orientadorId && $ator['perfil'] === Perfil::RESPONSAVEL) {
            throw HttpException::unprocessable(['orientadorId' => 'Informe o orientador.']);
        }
        $rows = $orientadorId ? $this->repo->byOrientador($orientadorId) : $this->repo->all();
        usort($rows, fn ($a, $b) => [$a['orientadorId'], $a['dia'], $a['inicio']] <=> [$b['orientadorId'], $b['dia'], $b['inicio']]);
        return array_map([$this, 'apresentar'], $rows);
    }

    public function criar(array $ator, array $in): array
    {
        return $this->lock->run(function () use ($ator, $in) {
            $d = $this->validar($in);
            $orientadorId = $this->orientadorDoPedido($ator, $in['orientadorId'] ?? null);
            $this->exigirSemSobreposicao($orientadorId, $d['dia'], $d['inicio'], $d['fim']);
            $r = $this->repo->create(['orientadorId' => $orientadorId] + $d);
            $this->audit->log($ator, "Cadastrou disponibilidade de {$this->nome($orientadorId)}: " . self::DIAS[$d['dia']] . ", {$d['inicio']}–{$d['fim']}", Categoria::DISPONIBILIDADE);
            return $this->apresentar($r);
        });
    }

    public function atualizar(array $ator, string $id, array $in): array
    {
        return $this->lock->run(function () use ($ator, $id, $in) {
            $atual = $this->dono($ator, $id);
            $d = $this->validar($in);
            $this->exigirSemSobreposicao($atual['orientadorId'], $d['dia'], $d['inicio'], $d['fim'], $id);
            $r = $this->repo->update($id, $d);
            $this->audit->log($ator, "Alterou disponibilidade de {$this->nome($r['orientadorId'])}: " . self::DIAS[$d['dia']] . ", {$d['inicio']}–{$d['fim']}", Categoria::DISPONIBILIDADE);
            return $this->apresentar($r);
        });
    }

    public function excluir(array $ator, string $id): void
    {
        $this->lock->run(function () use ($ator, $id) {
            $d = $this->dono($ator, $id);
            $this->repo->delete($id);
            $this->audit->log($ator, "Excluiu disponibilidade de {$this->nome($d['orientadorId'])}: " . self::DIAS[$d['dia']] . ", {$d['inicio']}–{$d['fim']}", Categoria::DISPONIBILIDADE);
        });
    }

    /** Horários que o responsável pode escolher numa data (faixas de INÍCIO com precisão de minuto). */
    public function horariosLivres(string $orientadorId, string $data): array
    {
        if (!Tempo::dataValida($data)) {
            throw HttpException::unprocessable(['data' => 'Data inválida (use AAAA-MM-DD).']);
        }
        $this->exigirOrientador($orientadorId);
        $bloqueado = $this->agenda->diaBloqueadoPorReuniao($data);
        return [
            'orientadorId' => $orientadorId, 'data' => $data, 'duracaoMinutos' => AgendaService::DURACAO,
            'temDisponibilidade' => $this->agenda->temDisponibilidade($orientadorId),
            'bloqueadoPorReuniao' => $bloqueado,
            'faixas' => $bloqueado ? [] : $this->agenda->faixasLivres($orientadorId, $data),
        ];
    }

    // ---------- internos ----------
    private function validar(array $in): array
    {
        $d = Validator::validate($in, ['dia' => 'required|int', 'inicio' => 'required|time', 'fim' => 'required|time', 'intervalos' => 'array']);
        $d['dia'] = (int) $d['dia'];
        $d['inicio'] = Tempo::hhmm($d['inicio']);
        $d['fim'] = Tempo::hhmm($d['fim']);
        if ($d['dia'] < 0 || $d['dia'] > 6) {
            throw HttpException::unprocessable(['dia' => 'Dia da semana de 0 (domingo) a 6 (sábado).']);
        }
        if (Tempo::minutos($d['fim']) <= Tempo::minutos($d['inicio'])) {
            throw HttpException::unprocessable(['fim' => 'O horário final deve ser depois do inicial.']);
        }
        if (Tempo::minutos($d['fim']) - Tempo::minutos($d['inicio']) < AgendaService::DURACAO) {
            throw HttpException::unprocessable(['fim' => 'O período precisa ter ao menos ' . AgendaService::DURACAO . ' minutos (duração de um atendimento).']);
        }
        $d['intervalos'] = $this->validarIntervalos($d['intervalos'] ?? [], $d['inicio'], $d['fim']);
        return $d;
    }

    /** Até 5 intervalos, cada um dentro do período e sem sobreposição entre si. */
    private function validarIntervalos(array $lista, string $ini, string $fim): array
    {
        if (count($lista) > self::MAX_INTERVALOS) {
            throw HttpException::unprocessable(['intervalos' => 'Use no máximo ' . self::MAX_INTERVALOS . ' intervalos.']);
        }
        $out = [];
        foreach (array_values($lista) as $k => $i) {
            $n = $k + 1;
            $a = is_array($i) ? Tempo::hhmm((string) ($i['inicio'] ?? '')) : null;
            $b = is_array($i) ? Tempo::hhmm((string) ($i['fim'] ?? '')) : null;
            if ($a === null || $b === null) {
                throw HttpException::unprocessable(['intervalos' => "Intervalo $n: informe início e fim (HH:MM)."]);
            }
            if (Tempo::minutos($b) <= Tempo::minutos($a)) {
                throw HttpException::unprocessable(['intervalos' => "Intervalo $n: o horário final deve ser depois do inicial."]);
            }
            if (Tempo::minutos($a) < Tempo::minutos($ini) || Tempo::minutos($b) > Tempo::minutos($fim)) {
                throw HttpException::unprocessable(['intervalos' => "Intervalo $n: precisa ficar dentro do período ($ini às $fim)."]);
            }
            $out[] = ['inicio' => $a, 'fim' => $b];
        }
        usort($out, fn ($x, $y) => strcmp($x['inicio'], $y['inicio']));
        for ($k = 1; $k < count($out); $k++) {
            if (Tempo::sobrepoe(Tempo::minutos($out[$k]['inicio']), Tempo::minutos($out[$k]['fim']), Tempo::minutos($out[$k - 1]['inicio']), Tempo::minutos($out[$k - 1]['fim']))) {
                throw HttpException::unprocessable(['intervalos' => "Os intervalos {$out[$k - 1]['inicio']}–{$out[$k - 1]['fim']} e {$out[$k]['inicio']}–{$out[$k]['fim']} se sobrepõem."]);
            }
        }
        return $out;
    }

    private function exigirSemSobreposicao(string $orientadorId, int $dia, string $ini, string $fim, ?string $ignorar = null): void
    {
        foreach ($this->repo->byOrientador($orientadorId) as $o) {
            if ($o['id'] !== $ignorar && $o['dia'] === $dia && Tempo::sobrepoe(Tempo::minutos($ini), Tempo::minutos($fim), Tempo::minutos($o['inicio']), Tempo::minutos($o['fim']))) {
                throw HttpException::conflict("Conflito com a disponibilidade de {$o['inicio']} às {$o['fim']} nesse dia.");
            }
        }
    }

    private function orientadorDoPedido(array $ator, ?string $id): string
    {
        $oid = $ator['perfil'] === Perfil::ORIENTADOR ? $ator['id'] : $id;
        if (!$oid) {
            throw HttpException::unprocessable(['orientadorId' => 'Informe o orientador.']);
        }
        $this->exigirOrientador($oid);
        return $oid;
    }

    private function exigirOrientador(string $id): void
    {
        $u = $this->users->find($id);
        if (!$u || $u['perfil'] !== Perfil::ORIENTADOR) {
            throw HttpException::notFound('Orientador não encontrado.');
        }
    }

    private function dono(array $ator, string $id): array
    {
        $d = $this->repo->find($id);
        if (!$d || ($ator['perfil'] === Perfil::ORIENTADOR && $d['orientadorId'] !== $ator['id'])) {
            throw HttpException::notFound('Disponibilidade não encontrada.');
        }
        return $d;
    }

    private function nome(string $id): string { return ($this->users->find($id)['nome'] ?? $id); }

    private function apresentar(array $d): array
    {
        return $d + ['intervalos' => [], 'diaNome' => self::DIAS[$d['dia']], 'orientadorNome' => $this->nome($d['orientadorId'])];
    }
}
