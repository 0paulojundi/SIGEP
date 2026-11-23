<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\AgendaLockInterface;
use Sigep\Core\Config;
use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Models\StatusAtendimento as S;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Tempo;

/**
 * RF02 (solicitações), RF04 (agendamentos), RF06 (status) e RF12 (conflito de horários).
 *
 * Fluxo: o responsável SOLICITA (Pendente) → o ORIENTADOR aprova (Confirmado) ou recusa (Recusado).
 * A Instituição NÃO vê nem decide solicitações. Orientador e Instituição podem AGENDAR direto (nasce Confirmado).
 * Toda regra de horário vem do AgendaService e roda DENTRO da trava da agenda (sem reservas duplicadas por concorrência).
 */
final class AgendamentoService
{
    /** Fase de solicitação: invisível para a Instituição. */
    private const FASE_SOLICITACAO = [S::PENDENTE, S::RECUSADO];
    private const HISTORICO = [S::FINALIZADO, S::CANCELADO, S::RECUSADO];

    public function __construct(
        private AgendamentoRepositoryInterface $repo,
        private AlunoRepositoryInterface $alunos,
        private UsuarioRepositoryInterface $users,
        private AgendaService $agenda,
        private AgendaLockInterface $lock,
        private NotificationService $notif,
        private AuditService $audit,
        private Config $cfg
    ) {
    }

    // ---------- consultas ----------
    public function listar(array $ator, array $q): array
    {
        $this->sincronizarStatus();
        $rows = match ($ator['perfil']) {
            Perfil::ADMIN => array_filter($this->repo->all(), fn ($r) => !in_array($r['status'], self::FASE_SOLICITACAO, true)),
            Perfil::ORIENTADOR => Env::bool('ORIENTADOR_VE_TODOS', true) ? $this->repo->all() : $this->repo->byOrientador($ator['id']),
            default => array_filter($this->repo->all(), fn ($r) => $this->doResponsavel($ator['id'], $r)),
        };
        if (!empty($q['status'])) {
            $st = array_map('trim', explode(',', (string) $q['status']));
            $rows = array_filter($rows, fn ($r) => in_array($r['status'], $st, true));
        }
        // "Meu agendamento" = em andamento; "Histórico" = o que já terminou (finalizado, cancelado, recusado)
        if (($q['situacao'] ?? '') === 'andamento') {
            $rows = array_filter($rows, fn ($r) => in_array($r['status'], S::ABERTOS, true));
        } elseif (($q['situacao'] ?? '') === 'historico') {
            $rows = array_filter($rows, fn ($r) => in_array($r['status'], self::HISTORICO, true));
        }
        foreach (['alunoRm', 'orientadorId', 'origem'] as $f) {
            if (!empty($q[$f])) {
                $rows = array_filter($rows, fn ($r) => ($r[$f] ?? null) === $q[$f]);
            }
        }
        if (!empty($q['de'])) { $rows = array_filter($rows, fn ($r) => $r['data'] >= $q['de']); }
        if (!empty($q['ate'])) { $rows = array_filter($rows, fn ($r) => $r['data'] <= $q['ate']); }
        usort($rows, fn ($a, $b) => strcmp($a['data'] . $a['horario'], $b['data'] . $b['horario']));
        return array_map(fn ($r) => $this->apresentar($r, $ator), array_values($rows));
    }

    public function obter(array $ator, string $id): array
    {
        return $this->apresentar($this->carregar($ator, $id), $ator);
    }

    // ---------- solicitação (responsável) ----------
    public function solicitar(array $ator, array $in): array
    {
        if ($ator['perfil'] !== Perfil::RESPONSAVEL) {
            throw HttpException::forbidden('Somente o responsável solicita atendimento.');
        }
        $d = $this->validarDados($in, true);
        return $this->lock->run(function () use ($ator, $d) {
            $aluno = $this->alunos->find($d['alunoRm']);
            if (!$aluno || !in_array($ator['id'], $aluno['responsaveisIds'] ?? [], true)) {
                throw HttpException::forbidden('Este aluno não está vinculado ao seu cadastro.');
            }
            $this->exigirOrientadorAtivo($d['orientadorId']);
            $this->exigirDataFutura($d['data'], $d['horario']);
            $this->agenda->exigirAtendimentoPossivel($d['orientadorId'], $d['alunoRm'], $ator['id'], $d['data'], $d['horario']);

            $r = $this->repo->create($this->novo($d, $aluno, S::PENDENTE, 'solicitacao', $ator['id']) + ['solicitadoPor' => $ator['id']]);
            $this->audit->log($ator, "Nova solicitação de atendimento {$r['id']} ({$aluno['nome']}) para " . $this->br($r['data']) . " às {$r['horario']}", Categoria::SOLICITACOES);
            $this->notif->notificar([$r['orientadorId']], "Nova solicitação de atendimento: {$aluno['nome']}, " . $this->br($r['data']) . " às {$r['horario']}.", Categoria::SOLICITACOES, $ator['id'], ['tipo' => 'agendamento', 'id' => $r['id']]);
            return $this->apresentar($r, $ator);
        });
    }

    public function aprovar(array $ator, string $id): array
    {
        $this->exigirOrientador($ator);
        return $this->lock->run(function () use ($ator, $id) {
            $r = $this->carregar($ator, $id);
            $this->exigirGestor($ator, $r);
            if ($r['status'] !== S::PENDENTE) {
                throw HttpException::conflict('Só solicitações pendentes podem ser aprovadas.');
            }
            // confere TUDO de novo: a agenda pode ter mudado desde a solicitação
            $this->agenda->exigirAtendimentoPossivel($r['orientadorId'], $r['alunoRm'], $this->responsaveisDoAluno($r['alunoRm']), $r['data'], $r['horario'], $id);
            $novo = $this->repo->update($id, ['status' => S::CONFIRMADO, 'decididoPor' => $ator['id'], 'decididoEm' => date('c'), 'atualizadoEm' => date('c')]);
            $this->audit->log($ator, "Aprovou a solicitação {$id}", Categoria::SOLICITACOES);
            $this->avisar($novo, $ator, 'foi aprovada', Categoria::SOLICITACOES);
            return $this->apresentar($novo, $ator);
        });
    }

    public function recusar(array $ator, string $id, array $in): array
    {
        $this->exigirOrientador($ator);
        return $this->lock->run(function () use ($ator, $id, $in) {
            $r = $this->carregar($ator, $id);
            $this->exigirGestor($ator, $r);
            if ($r['status'] !== S::PENDENTE) {
                throw HttpException::conflict('Só solicitações pendentes podem ser recusadas.');
            }
            $d = Validator::validate($in, ['motivoRecusa' => 'nullable|string|max:300']);
            $novo = $this->repo->update($id, ['status' => S::RECUSADO, 'motivoRecusa' => $d['motivoRecusa'] ?? null, 'decididoPor' => $ator['id'], 'decididoEm' => date('c'), 'atualizadoEm' => date('c')]);
            $this->audit->log($ator, "Recusou a solicitação {$id}", Categoria::SOLICITACOES);
            $this->avisar($novo, $ator, 'foi recusada', Categoria::SOLICITACOES);
            return $this->apresentar($novo, $ator);
        });
    }

    // ---------- agendamento direto (orientador/instituição): nasce CONFIRMADO ----------
    public function agendarDireto(array $ator, array $in): array
    {
        $d = $this->validarDados($in, false);
        $d['orientadorId'] = $ator['perfil'] === Perfil::ORIENTADOR ? $ator['id'] : ($d['orientadorId'] ?? null);
        if (!$d['orientadorId']) {
            throw HttpException::unprocessable(['orientadorId' => 'Informe o orientador.']);
        }
        return $this->lock->run(function () use ($ator, $d) {
            $this->exigirOrientadorAtivo($d['orientadorId']);
            $aluno = $this->alunos->find($d['alunoRm']);
            if (!$aluno) {
                throw HttpException::unprocessable(['alunoRm' => 'Selecione um aluno cadastrado.']);
            }
            $this->exigirDataFutura($d['data'], $d['horario']);
            $this->agenda->exigirAtendimentoPossivel($d['orientadorId'], $d['alunoRm'], $aluno['responsaveisIds'] ?? [], $d['data'], $d['horario']);

            $r = $this->repo->create($this->novo($d, $aluno, S::CONFIRMADO, 'direta', ($aluno['responsaveisIds'][0] ?? '')) + ['solicitadoPor' => null, 'decididoPor' => $ator['id'], 'decididoEm' => date('c')]);
            $this->audit->log($ator, "Agendou atendimento direto {$r['id']} ({$aluno['nome']}) para " . $this->br($r['data']) . " às {$r['horario']}", Categoria::AGENDAMENTOS);
            $this->avisar($r, $ator, 'foi agendado', Categoria::AGENDAMENTOS);
            return $this->apresentar($r, $ator);
        });
    }

    // ---------- alteração e cancelamento ----------
    public function atualizar(array $ator, string $id, array $in): array
    {
        $d = Validator::validate($in, [
            'data' => 'date', 'horario' => 'time', 'motivo' => 'in:' . implode(',', $this->cfg->get('motivos')), 'descricao' => 'nullable|string|max:1000',
        ]);
        if (!$d) {
            throw HttpException::unprocessable(['corpo' => 'Informe ao menos um campo para alterar.']);
        }
        if (isset($d['horario'])) { $d['horario'] = Tempo::hhmm($d['horario']); }
        return $this->lock->run(function () use ($ator, $id, $d) {
            $r = $this->carregar($ator, $id);
            $this->exigirPodeAlterar($ator, $r);
            if (!in_array($r['status'], [S::PENDENTE, S::CONFIRMADO], true)) {
                throw HttpException::conflict('Só agendamentos Pendentes ou Confirmados podem ser alterados.');
            }
            if ($ator['perfil'] === Perfil::RESPONSAVEL && $r['status'] !== S::PENDENTE) {
                throw HttpException::forbidden('Depois de aprovado, só o orientador pode alterar. Você pode cancelar e solicitar novamente.');
            }
            $data = $d['data'] ?? $r['data'];
            $hora = $d['horario'] ?? $r['horario'];
            $motivo = $d['motivo'] ?? $r['motivo'];
            $desc = array_key_exists('descricao', $d) ? (string) $d['descricao'] : (string) $r['descricao'];
            $this->exigirDescricaoSeOutro($motivo, $desc);
            $remarcou = $data !== $r['data'] || $hora !== $r['horario'];
            if ($remarcou) {
                $this->exigirDataFutura($data, $hora);
                $this->agenda->exigirAtendimentoPossivel($r['orientadorId'], $r['alunoRm'], $this->responsaveisDoAluno($r['alunoRm']), $data, $hora, $id);
            }
            $novo = $this->repo->update($id, $d + ['atualizadoEm' => date('c')]);
            if ($remarcou) {
                $this->audit->log($ator, "Alterou o agendamento {$id} para " . $this->br($data) . " às {$hora}", Categoria::AGENDAMENTOS);
                $this->avisar($novo, $ator, 'foi alterado para ' . $this->br($data) . " às {$hora}", Categoria::AGENDAMENTOS, true);
            } else {
                $this->audit->log($ator, "Alterou dados do agendamento {$id}", Categoria::AGENDAMENTOS);
            }
            return $this->apresentar($novo, $ator);
        });
    }

    /** Cancelar libera o horário na hora (o AgendaService ignora cancelados) e mantém o registro no histórico. */
    public function cancelar(array $ator, string $id, array $in): array
    {
        return $this->lock->run(function () use ($ator, $id, $in) {
            $r = $this->carregar($ator, $id);
            $this->exigirPodeAlterar($ator, $r);
            $permitidos = $ator['perfil'] === Perfil::RESPONSAVEL ? [S::PENDENTE, S::CONFIRMADO] : S::ABERTOS;
            if (!in_array($r['status'], $permitidos, true)) {
                throw HttpException::conflict('Este agendamento não pode mais ser cancelado por você.');
            }
            $d = Validator::validate($in, ['motivo' => 'nullable|string|max:300']);
            $novo = $this->repo->update($id, ['status' => S::CANCELADO, 'motivoCancelamento' => $d['motivo'] ?? null, 'canceladoPor' => $ator['id'], 'atualizadoEm' => date('c')]);
            $this->audit->log($ator, "Cancelou o agendamento {$id}", Categoria::AGENDAMENTOS);
            $this->avisar($novo, $ator, 'foi cancelado', Categoria::AGENDAMENTOS);
            return $this->apresentar($novo, $ator);
        });
    }

    /** Evento 14.2 — o orientador liga/desliga o aviso ao responsável neste agendamento. */
    public function definirAvisoAoResponsavel(array $ator, string $id, mixed $valor): array
    {
        $r = $this->carregar($ator, $id);
        $this->exigirGestor($ator, $r);
        if (!is_bool($valor)) {
            throw HttpException::unprocessable(['notificarResponsavel' => 'Deve ser verdadeiro ou falso.']);
        }
        $novo = $this->repo->update($id, ['notificarResponsavel' => $valor]);
        $this->audit->log($ator, ($valor ? 'Ativou' : 'Desativou') . " o aviso ao responsável no agendamento {$id}", Categoria::AGENDAMENTOS);
        return $this->apresentar($novo, $ator);
    }

    /**
     * RF06 — status automático (desligue tudo com AUTO_STATUS=false):
     *  • solicitação Pendente cujo horário já passou → Cancelado;
     *  • agendamento Confirmado que não foi iniciado em ATRASO_CANCELA_MINUTOS (padrão 10; 0 desliga) → Cancelado.
     */
    public function sincronizarStatus(): void
    {
        if (!Env::bool('AUTO_STATUS', true)) {
            return;
        }
        $tolerancia = Env::int('ATRASO_CANCELA_MINUTOS', 10);
        foreach ($this->repo->all() as $r) {
            if ($tolerancia > 0 && $r['status'] === S::CONFIRMADO && Tempo::atrasoMin($r['data'], $r['horario']) > $tolerancia) {
                $novo = $this->repo->update($r['id'], ['status' => S::CANCELADO, 'motivoCancelamento' => "Atraso superior a {$tolerancia} minutos.", 'atualizadoEm' => date('c')]);
                $this->audit->log(null, "Agendamento {$r['id']} cancelado automaticamente: atraso superior a {$tolerancia} minutos", Categoria::AGENDAMENTOS);
                $this->avisar($novo, null, "foi cancelado automaticamente (atraso superior a {$tolerancia} minutos)", Categoria::AGENDAMENTOS);
                continue;
            }
            if ($r['status'] === S::PENDENTE && Tempo::passou($r['data'], $r['horario'])) {
                $novo = $this->repo->update($r['id'], ['status' => S::CANCELADO, 'motivoCancelamento' => 'Solicitação expirada sem resposta.', 'atualizadoEm' => date('c')]);
                $this->audit->log(null, "Solicitação {$r['id']} expirou sem resposta e foi cancelada", Categoria::SOLICITACOES);
                $this->avisar($novo, null, 'expirou sem resposta e foi cancelada', Categoria::SOLICITACOES);
            }
        }
    }

    // ---------- usados também pelos outros serviços ----------
    public function carregar(array $ator, string $id): array
    {
        $r = $this->repo->find($id);
        if (!$r || !$this->visivel($ator, $r)) {
            throw HttpException::notFound('Agendamento não encontrado.');
        }
        return $r;
    }

    public function visivel(array $ator, array $r): bool
    {
        return match ($ator['perfil']) {
            Perfil::ADMIN => !in_array($r['status'], self::FASE_SOLICITACAO, true),
            Perfil::ORIENTADOR => Env::bool('ORIENTADOR_VE_TODOS', true) || $r['orientadorId'] === $ator['id'],
            default => $this->doResponsavel($ator['id'], $r),
        };
    }

    /** O agendamento é de um aluno vinculado a este responsável (N:N) ou foi ele que solicitou? */
    public function doResponsavel(string $responsavelId, array $r): bool
    {
        return $r['responsavelId'] === $responsavelId || in_array($responsavelId, $this->responsaveisDoAluno($r['alunoRm']), true);
    }

    public function responsaveisDoAluno(string $rm): array
    {
        return $this->alunos->find($rm)['responsaveisIds'] ?? [];
    }

    /** Orientador responsável pelo registro (a Instituição não decide solicitações; para outras ações, ver exigirGestorOuAdmin). */
    public function exigirGestor(array $ator, array $r): void
    {
        if ($ator['perfil'] !== Perfil::ADMIN && !($ator['perfil'] === Perfil::ORIENTADOR && $r['orientadorId'] === $ator['id'])) {
            throw HttpException::forbidden();
        }
    }

    public function apresentar(array $r, array $ator): array
    {
        $nomes = array_values(array_filter(array_map(fn ($id) => $this->users->find($id)['nome'] ?? null, $this->responsaveisDoAluno($r['alunoRm']))));
        $out = $r + [
            'alunoNome' => $this->alunos->find($r['alunoRm'])['nome'] ?? null,
            'orientadorNome' => $this->users->find($r['orientadorId'])['nome'] ?? null,
            'responsavelNome' => $this->users->find($r['responsavelId'])['nome'] ?? null,
            'responsaveisNomes' => $nomes,
            'horarioFim' => Tempo::deMinutos(Tempo::minutos($r['horario']) + AgendaService::DURACAO),
        ];
        if ($ator['perfil'] === Perfil::RESPONSAVEL) {
            // proteção das informações pedagógicas: observações são sempre internas; o registro do atendimento
            // (descrição e resultado) só aparece se o orientador liberou ("registroVisivel").
            unset($out['observacoes'], $out['decididoPor'], $out['solicitadoPor'], $out['canceladoPor']);
            if (in_array($r['status'], [S::EM_ANDAMENTO, S::FINALIZADO], true) && empty($r['registroVisivel'])) {
                unset($out['descricao'], $out['resultado']);
            }
        }
        return $out;
    }

    public function avisar(array $r, ?array $ator, string $acaoTexto, string $cat, bool $textoCompleto = false): void
    {
        $aluno = $this->alunos->find($r['alunoRm'])['nome'] ?? $r['alunoRm'];
        $texto = $textoCompleto
            ? "O agendamento de {$aluno} {$acaoTexto}."
            : "O atendimento de {$aluno} em " . $this->br($r['data']) . " às {$r['horario']} {$acaoTexto}.";
        $destinos = [$r['orientadorId']];
        if (($r['notificarResponsavel'] ?? true) !== false) {
            $destinos = array_merge($destinos, $this->responsaveisDoAluno($r['alunoRm']), [$r['responsavelId']]);
        }
        $this->notif->notificar($destinos, $texto, $cat, $ator['id'] ?? null, ['tipo' => 'agendamento', 'id' => $r['id']]);
    }

    public function br(string $data): string { return date('d/m/Y', strtotime($data . ' 12:00')); }

    // ---------- internos ----------
    private function validarDados(array $in, bool $comOrientadorObrigatorio): array
    {
        $d = Validator::validate($in, [
            'alunoRm' => 'required|string', 'orientadorId' => ($comOrientadorObrigatorio ? 'required|' : 'nullable|') . 'string', 'data' => 'required|date', 'horario' => 'required|time',
            'motivo' => 'required|in:' . implode(',', $this->cfg->get('motivos')), 'descricao' => 'nullable|string|max:1000',
        ]);
        $d['horario'] = Tempo::hhmm($d['horario']);
        $this->exigirDescricaoSeOutro($d['motivo'], (string) ($d['descricao'] ?? ''));
        return $d;
    }

    /** Motivo "Outro" exige a descrição do motivo específico. */
    private function exigirDescricaoSeOutro(string $motivo, string $descricao): void
    {
        if ($motivo === 'Outro' && trim($descricao) === '') {
            throw HttpException::unprocessable(['descricao' => 'Descreva o motivo específico do atendimento.']);
        }
    }

    private function novo(array $d, array $aluno, string $status, string $origem, string $responsavelId): array
    {
        return [
            'alunoRm' => $d['alunoRm'], 'orientadorId' => $d['orientadorId'], 'responsavelId' => $responsavelId,
            'data' => $d['data'], 'horario' => $d['horario'], 'motivo' => $d['motivo'], 'descricao' => $d['descricao'] ?? '',
            'observacoes' => '', 'resultado' => '', 'status' => $status, 'origem' => $origem, 'notificarResponsavel' => true, 'registroVisivel' => false,
            'horaInicialReal' => null, 'horaFinalReal' => null, 'criadoEm' => date('Y-m-d'), 'atualizadoEm' => date('c'),
        ];
    }

    private function exigirOrientador(array $ator): void
    {
        if ($ator['perfil'] !== Perfil::ORIENTADOR) {
            throw HttpException::forbidden('Somente o orientador decide sobre solicitações de atendimento.');
        }
    }

    private function exigirPodeAlterar(array $ator, array $r): void
    {
        if ($ator['perfil'] === Perfil::RESPONSAVEL) {
            if (!$this->doResponsavel($ator['id'], $r)) {
                throw HttpException::forbidden();
            }
            return;
        }
        $this->exigirGestor($ator, $r);
    }

    private function exigirOrientadorAtivo(string $id): void
    {
        $o = $this->users->find($id);
        if (!$o || $o['perfil'] !== Perfil::ORIENTADOR || $o['status'] !== 'Ativo') {
            throw HttpException::unprocessable(['orientadorId' => 'Selecione um orientador ativo.']);
        }
    }

    private function exigirDataFutura(string $data, string $horario): void
    {
        if ($data < Tempo::hoje() || ($data === Tempo::hoje() && Tempo::passou($data, $horario))) {
            throw HttpException::unprocessable(['data' => 'A data e o horário não podem estar no passado.']);
        }
    }
}
