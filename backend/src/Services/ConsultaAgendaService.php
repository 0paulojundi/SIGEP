<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Perfil;
use Sigep\Models\StatusAtendimento as S;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Tempo;

/**
 * Consultas que juntam atendimentos E reuniões num só lugar:
 *  • agenda  — compromissos (ocupam horário) de quem consulta;
 *  • histórico — registro permanente do que já aconteceu (Instituição: atendimentos + reuniões, com o "tipo" de cada um);
 *  • ocupação — horários já ocupados de um orientador numa data (para a interface mostrar o que está ocupado).
 */
final class ConsultaAgendaService
{
    public function __construct(
        private AgendamentoService $ag,
        private ReuniaoService $reunioes,
        private AgendaService $agenda,
        private UsuarioRepositoryInterface $users
    ) {
    }

    public function agenda(array $ator, array $q): array
    {
        $f = Validator::validate($q, ['de' => 'date', 'ate' => 'date']);
        $comCancelados = filter_var($q['incluirCancelados'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $itens = [];
        foreach ($this->ag->listar($ator, $f) as $a) {
            if (!$comCancelados && in_array($a['status'], [S::CANCELADO, S::RECUSADO], true)) {
                continue;
            }
            $itens[] = $this->itemAtendimento($a);
        }
        foreach ($this->reunioes->listar($ator, []) as $r) {
            if ((!$comCancelados && $r['situacao'] === 'Cancelada') || (!empty($f['de']) && $r['data'] < $f['de']) || (!empty($f['ate']) && $r['data'] > $f['ate'])) {
                continue;
            }
            $itens[] = $this->itemReuniao($r);
        }
        usort($itens, fn ($x, $y) => strcmp($x['data'] . $x['inicio'], $y['data'] . $y['inicio']));
        return $itens;
    }

    public function historico(array $ator, array $q): array
    {
        $f = Validator::validate($q, ['de' => 'date', 'ate' => 'date', 'tipo' => 'in:atendimento,reuniao']);
        $comCancelados = filter_var($q['incluirCancelados'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tipo = $f['tipo'] ?? '';
        $itens = [];
        if ($tipo !== 'reuniao') {
            // responsável: tudo o que não está mais em andamento; Instituição/orientador: atendimentos realizados
            $status = $ator['perfil'] === Perfil::RESPONSAVEL ? [S::FINALIZADO, S::CANCELADO, S::RECUSADO] : ($comCancelados ? [S::FINALIZADO, S::CANCELADO] : [S::FINALIZADO]);
            foreach ($this->ag->listar($ator, ['status' => implode(',', $status)] + array_intersect_key($f, ['de' => 1, 'ate' => 1])) as $a) {
                $itens[] = $this->itemAtendimento($a, true);
            }
        }
        if ($tipo !== 'atendimento' && $ator['perfil'] !== Perfil::RESPONSAVEL) {
            foreach ($this->reunioes->listar($ator, []) as $r) {
                $ok = $r['situacao'] === 'Realizada' || ($comCancelados && $r['situacao'] === 'Cancelada');
                if ($ok && (empty($f['de']) || $r['data'] >= $f['de']) && (empty($f['ate']) || $r['data'] <= $f['ate'])) {
                    $itens[] = $this->itemReuniao($r, true);
                }
            }
        }
        usort($itens, fn ($x, $y) => strcmp($y['data'] . $y['inicio'], $x['data'] . $x['inicio']));
        return $itens;
    }

    public function ocupacao(array $ator, string $orientadorId, string $data): array
    {
        if (!Tempo::dataValida($data)) {
            throw HttpException::unprocessable(['data' => 'Data inválida (use AAAA-MM-DD).']);
        }
        $o = $this->users->find($orientadorId);
        if (!$o || $o['perfil'] !== Perfil::ORIENTADOR) {
            throw HttpException::notFound('Orientador não encontrado.');
        }
        $detalhe = $ator['perfil'] !== Perfil::RESPONSAVEL; // responsável só vê "ocupado", sem detalhes
        return [
            'orientadorId' => $orientadorId, 'data' => $data,
            'bloqueadoPorReuniao' => $this->agenda->diaBloqueadoPorReuniao($data),
            'reunioesNoDia' => count($this->agenda->reunioesDoDia($data)),
            'ocupados' => array_map(fn ($c) => ['tipo' => $c['tipo'], 'inicio' => Tempo::deMinutos($c['ini']), 'fim' => Tempo::deMinutos($c['fim'])]
                + ($detalhe ? ['id' => $c['id'], 'titulo' => $c['titulo']] : []), $this->agenda->compromissosDaPessoa($orientadorId, $data)),
        ];
    }

    private function itemAtendimento(array $a, bool $completo = false): array
    {
        $item = [
            'tipo' => 'atendimento', 'id' => $a['id'], 'data' => $a['data'], 'inicio' => $a['horario'], 'fim' => $a['horarioFim'],
            'titulo' => ($a['alunoNome'] ?? '') . ' — ' . $a['motivo'], 'status' => $a['status'],
            'participantes' => array_values(array_filter(array_merge([$a['orientadorNome'] ?? null], $a['responsaveisNomes'] ?? []))),
        ];
        if ($completo) {
            foreach (['descricao', 'resultado', 'alunoNome', 'orientadorNome'] as $k) {
                if (array_key_exists($k, $a)) { $item[$k] = $a[$k]; }
            }
        }
        return $item;
    }

    private function itemReuniao(array $r, bool $completo = false): array
    {
        $item = [
            'tipo' => 'reuniao', 'id' => $r['id'], 'data' => $r['data'], 'inicio' => $r['horario'], 'fim' => $r['horarioFim'],
            'titulo' => $r['assunto'], 'status' => $r['situacao'], 'participantes' => array_column($r['participantesDetalhe'], 'nome'),
        ];
        if ($completo) { $item['descricao'] = $r['descricao'] ?? ''; }
        return $item;
    }
}
