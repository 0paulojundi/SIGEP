<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;
use Sigep\Models\Perfil;
use Sigep\Models\StatusAtendimento as S;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Tempo;

/** RF08 — relatórios de atendimentos (resumo para gráficos e exportação CSV/SVG). */
final class RelatorioService
{
    public function __construct(private AgendamentoService $ag, private AlunoRepositoryInterface $alunos, private UsuarioRepositoryInterface $users)
    {
    }

    public function resumo(array $ator, array $q): array
    {
        $rows = $this->linhas($ator, $q);
        $porStatus = array_fill_keys(S::todos(), 0);
        $porMes = $porCurso = $porOrientador = [];
        $duracoes = [];
        foreach ($rows as $r) {
            $porStatus[$r['status']]++;
            $mes = Tempo::mes($r['data']);
            $porMes[$mes] = ($porMes[$mes] ?? 0) + 1;
            $curso = $this->alunos->find($r['alunoRm'])['curso'] ?? 'Sem curso';
            $porCurso[$curso] = ($porCurso[$curso] ?? 0) + 1;
            $porOrientador[$r['orientadorNome']] = ($porOrientador[$r['orientadorNome']] ?? 0) + 1;
            if ($r['status'] === S::FINALIZADO && $r['horaInicialReal'] && $r['horaFinalReal']) {
                $duracoes[] = max(0, Tempo::minutos($r['horaFinalReal']) - Tempo::minutos($r['horaInicialReal']));
            }
        }
        ksort($porMes);
        arsort($porCurso);
        if ($ator['perfil'] !== Perfil::ADMIN) {
            $porOrientador = [];
        }
        return [
            'total' => count($rows), 'porStatus' => $porStatus, 'porMes' => $porMes, 'porCurso' => $porCurso, 'porOrientador' => $porOrientador,
            'duracaoMediaMinutos' => $duracoes ? (int) round(array_sum($duracoes) / count($duracoes)) : null,
        ];
    }

    /** Exportação em CSV ou SVG vetorial. */
    public function exportar(array $ator, array $q): array
    {
        $formato = strtolower((string) ($q['formato'] ?? 'csv'));
        $rows = $this->linhas($ator, $q);
        if ($formato === 'svg') {
            $contagens = array_fill_keys(S::todos(), 0);
            foreach ($rows as $r) { $contagens[$r['status']] = ($contagens[$r['status']] ?? 0) + 1; }
            $max = max(1, ...array_values($contagens));
            $elementos = [];
            $y = 88;
            foreach ($contagens as $status => $total) {
                $largura = (int) round(($total / $max) * 420);
                $rotulo = htmlspecialchars($status, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $elementos[] = '<text x="24" y="' . ($y + 19) . '" font-size="14" fill="#333">' . $rotulo . '</text>';
                $elementos[] = '<rect x="190" y="' . $y . '" width="' . $largura . '" height="26" rx="4" fill="#B20000" />';
                $elementos[] = '<text x="' . (200 + $largura) . '" y="' . ($y + 19) . '" font-size="14" fill="#333">' . $total . '</text>';
                $y += 48;
            }
            $total = count($rows);
            $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<svg xmlns="http://www.w3.org/2000/svg" width="760" height="' . ($y + 30) . '" viewBox="0 0 760 ' . ($y + 30) . '">' .
                '<rect width="100%" height="100%" fill="#fff" />' .
                '<text x="24" y="36" font-size="22" font-weight="700" fill="#222">Relatório SIGEP — Atendimentos</text>' .
                '<text x="24" y="61" font-size="13" fill="#666">Total de registros: ' . $total . '</text>' .
                implode('', $elementos) . '</svg>';
            return ['conteudo' => $svg, 'arquivo' => 'relatorio_sigep_' . date('Ymd_His') . '.svg', 'mime' => 'image/svg+xml; charset=utf-8'];
        }
        if ($formato !== 'csv') {
            throw HttpException::notImplemented('Formato não implementado. Use formato=csv ou formato=svg.');
        }
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['ID', 'Data', 'Horário', 'Aluno', 'RM', 'Curso', 'Orientador', 'Responsável', 'Motivo', 'Status', 'Origem', 'Início real', 'Fim real'], ';', '"', '');
        foreach ($rows as $r) {
            $curso = $this->alunos->find($r['alunoRm'])['curso'] ?? '';
            fputcsv($h, array_map([$this, 'seguro'], [
                $r['id'], date('d/m/Y', strtotime($r['data'])), $r['horario'], $r['alunoNome'], $r['alunoRm'], $curso, $r['orientadorNome'],
                $r['responsavelNome'], $r['motivo'], $r['status'], $r['origem'] ?? '', $r['horaInicialReal'] ?? '', $r['horaFinalReal'] ?? '',
            ]), ';', '"', '');
        }
        rewind($h);
        return ['conteudo' => (string) stream_get_contents($h), 'arquivo' => 'atendimentos_' . date('Ymd_His') . '.csv', 'mime' => 'text/csv; charset=utf-8'];
    }

    private function linhas(array $ator, array $q): array
    {
        return $this->ag->listar($ator, array_intersect_key($q, array_flip(['de', 'ate', 'status', 'alunoRm', 'orientadorId'])));
    }

    /** Evita "injeção de fórmula" em planilhas (células que começam com = + - @). */
    private function seguro(mixed $v): string
    {
        $s = (string) $v;
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) ? "'" . $s : $s;
    }
}
