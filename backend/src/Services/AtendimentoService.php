<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Models\StatusAtendimento as S;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Support\Tempo;

/**
 * RF05 (conduzir atendimento), RF07 (consultas) e RF13 (histórico).
 * Um atendimento nasce de UM agendamento confirmado (RN008): Confirmado → Em Andamento → Finalizado.
 */
final class AtendimentoService
{
    public function __construct(
        private AgendamentoRepositoryInterface $repo,
        private AgendamentoService $ag,
        private AlunoService $alunos,
        private AuditService $audit
    ) {
    }

    public function listar(array $ator, array $q): array
    {
        $q['status'] = $q['status'] ?? implode(',', [S::CONFIRMADO, S::EM_ANDAMENTO, S::FINALIZADO, S::CANCELADO]);
        return $this->ag->listar($ator, $q);
    }

    public function obter(array $ator, string $id): array
    {
        return $this->ag->obter($ator, $id);
    }

    /** Eventos 5.1 e 5.2 — inicia e o sistema grava a hora inicial real. */
    public function iniciar(array $ator, string $id): array
    {
        $r = $this->conduzir($ator, $id);
        if ($r['status'] !== S::CONFIRMADO) {
            throw HttpException::conflict('Só atendimentos Confirmados podem ser iniciados.');
        }
        if ($r['data'] > Tempo::hoje()) {
            throw HttpException::unprocessable(['data' => 'Este atendimento é de uma data futura e ainda não pode ser iniciado.']);
        }
        $novo = $this->repo->update($id, ['status' => S::EM_ANDAMENTO, 'horaInicialReal' => date('H:i'), 'iniciadoEm' => date('c'), 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, "Iniciou o atendimento {$id}", Categoria::ATENDIMENTOS);
        $this->ag->avisar($novo, $ator, 'foi iniciado', Categoria::ATENDIMENTOS);
        return $this->ag->apresentar($novo, $ator);
    }

    /** Salva rascunho do registro (descrição, observações, resultado) sem finalizar. */
    public function registrar(array $ator, string $id, array $in): array
    {
        $r = $this->conduzir($ator, $id);
        if (!in_array($r['status'], [S::CONFIRMADO, S::EM_ANDAMENTO], true)) {
            throw HttpException::conflict('Atendimentos finalizados ou cancelados não podem mais ser editados.');
        }
        $d = Validator::validate($in, ['descricao' => 'nullable|string|max:5000', 'observacoes' => 'nullable|string|max:5000', 'resultado' => 'nullable|string|max:5000']);
        $novo = $this->repo->update($id, array_map(fn ($v) => $v ?? '', $d) + ['atualizadoEm' => date('c')]);
        $this->audit->log($ator, "Atualizou o registro do atendimento {$id}", Categoria::ATENDIMENTOS); // sem o conteúdo (LGPD)
        return $this->ag->apresentar($novo, $ator);
    }

    /** Evento 5.5 — orientador ajusta as horas reais de início e fim. */
    public function ajustarHorarios(array $ator, string $id, array $in): array
    {
        $r = $this->conduzir($ator, $id);
        if (!in_array($r['status'], [S::EM_ANDAMENTO, S::FINALIZADO], true)) {
            throw HttpException::conflict('Só é possível ajustar horários de atendimentos iniciados ou finalizados.');
        }
        $d = Validator::validate($in, ['horaInicialReal' => 'time', 'horaFinalReal' => 'nullable|time']);
        $ini = isset($d['horaInicialReal']) ? Tempo::hhmm($d['horaInicialReal']) : $r['horaInicialReal'];
        $fim = array_key_exists('horaFinalReal', $d) ? ($d['horaFinalReal'] ? Tempo::hhmm($d['horaFinalReal']) : null) : $r['horaFinalReal'];
        if ($ini && $fim && Tempo::minutos($fim) <= Tempo::minutos($ini)) {
            throw HttpException::unprocessable(['horaFinalReal' => 'A hora final deve ser depois da inicial.']);
        }
        $this->exigirDuracaoMaxima($ini, $fim);
        $novo = $this->repo->update($id, ['horaInicialReal' => $ini, 'horaFinalReal' => $fim, 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, "Ajustou os horários reais do atendimento {$id}", Categoria::ATENDIMENTOS);
        return $this->ag->apresentar($novo, $ator);
    }

    /** Eventos 5.3 e 5.4 — encerra; exige descrição e resultado (mesma regra do front) e registra a hora final real. */
    public function finalizar(array $ator, string $id, array $in): array
    {
        $r = $this->conduzir($ator, $id);
        if ($r['status'] !== S::EM_ANDAMENTO) {
            throw HttpException::conflict('Só atendimentos Em Andamento podem ser finalizados.');
        }
        $d = Validator::validate($in, [
            'descricao' => 'nullable|string|max:5000', 'observacoes' => 'nullable|string|max:5000', 'resultado' => 'nullable|string|max:5000',
            'notificarResponsavel' => 'bool', 'registroVisivel' => 'bool',
        ]);
        $descricao = trim((string) ($d['descricao'] ?? $r['descricao']));
        $resultado = trim((string) ($d['resultado'] ?? $r['resultado']));
        $erros = [];
        if ($descricao === '') { $erros['descricao'] = 'Preencha a descrição antes de finalizar.'; }
        if ($resultado === '') { $erros['resultado'] = 'Preencha o resultado antes de finalizar.'; }
        if ($erros) {
            throw HttpException::unprocessable($erros, 'Preencha a descrição e o resultado antes de finalizar o atendimento.');
        }
        // respeita as horas reais que o orientador já ajustou; só preenche o que estiver vazio
        $ini = $r['horaInicialReal'] ?: $r['horario'];
        $fim = $r['horaFinalReal'] ?: date('H:i');
        $this->exigirDuracaoMaxima($ini, $fim);
        $novo = $this->repo->update($id, [
            // o orientador decide se avisa o responsável e se o registro fica visível a ele
            'notificarResponsavel' => $d['notificarResponsavel'] ?? ($r['notificarResponsavel'] ?? true),
            'registroVisivel' => $d['registroVisivel'] ?? ($r['registroVisivel'] ?? false),
            'status' => S::FINALIZADO, 'descricao' => $descricao, 'resultado' => $resultado,
            'observacoes' => trim((string) ($d['observacoes'] ?? $r['observacoes'])),
            'horaInicialReal' => $ini, 'horaFinalReal' => $fim,
            'finalizadoEm' => date('c'), 'atualizadoEm' => date('c'),
        ]);
        $this->audit->log($ator, "Finalizou o atendimento {$id}", Categoria::ATENDIMENTOS);
        $this->ag->avisar($novo, $ator, 'foi finalizado e registrado no histórico do aluno', Categoria::ATENDIMENTOS);
        return $this->ag->apresentar($novo, $ator);
    }

    /** RF13 — histórico pedagógico do aluno (atendimentos finalizados), respeitando quem pode ver. */
    public function historicoDoAluno(array $ator, string $rm): array
    {
        $aluno = $this->alunos->obter($ator, $rm); // 404 se o ator não pode ver este aluno
        $rows = array_filter($this->repo->byAluno($rm), fn ($r) => $r['status'] === S::FINALIZADO
            && ($ator['perfil'] !== Perfil::ORIENTADOR || Env::bool('ORIENTADOR_VE_TODOS', true) || $r['orientadorId'] === $ator['id']));
        usort($rows, fn ($a, $b) => strcmp($b['data'] . $b['horario'], $a['data'] . $a['horario']));
        return ['aluno' => $aluno, 'total' => count($rows), 'atendimentos' => array_map(fn ($r) => $this->ag->apresentar($r, $ator), array_values($rows))];
    }

    /** O orientador decide se o registro de um atendimento fica visível ao responsável (descrição e resultado; as observações são sempre internas). */
    public function definirVisibilidade(array $ator, string $id, mixed $valor): array
    {
        $r = $this->conduzir($ator, $id);
        if (!is_bool($valor)) {
            throw HttpException::unprocessable(['registroVisivel' => 'Deve ser verdadeiro ou falso.']);
        }
        $novo = $this->repo->update($id, ['registroVisivel' => $valor, 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, ($valor ? 'Liberou' : 'Ocultou') . " o registro do atendimento {$id} para o responsável", Categoria::ATENDIMENTOS);
        return $this->ag->apresentar($novo, $ator);
    }

    /** Duração máxima de um atendimento (padrão 180 min = 3 h; ATENDIMENTO_MAX_MINUTOS). */
    private function exigirDuracaoMaxima(?string $ini, ?string $fim): void
    {
        $max = Env::int('ATENDIMENTO_MAX_MINUTOS', 180);
        if ($max > 0 && $ini && $fim && Tempo::minutos($fim) - Tempo::minutos($ini) > $max) {
            throw HttpException::unprocessable(['horaFinalReal' => 'A duração máxima de um atendimento é de ' . intdiv($max, 60) . ' h' . ($max % 60 ? ' ' . ($max % 60) . ' min' : '') . '. Ajuste os horários reais.']);
        }
    }

    private function conduzir(array $ator, string $id): array
    {
        $r = $this->ag->carregar($ator, $id);
        $this->ag->exigirGestor($ator, $r);
        return $r;
    }
}
