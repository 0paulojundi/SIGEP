<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;
use Sigep\Models\Perfil;
use Sigep\Models\StatusAtendimento as S;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\DisponibilidadeRepositoryInterface;
use Sigep\Repositories\Contracts\ReuniaoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Tempo;

/**
 * ÚNICA fonte das regras de agenda (atendimentos e reuniões são compromissos reais que ocupam horário):
 *  • sobreposição REAL de intervalos (09:00–09:30 × 09:15–09:45 conflita; 09:00–09:30 × 09:30–10:00 não);
 *  • disponibilidade da orientadora, com minutos quebrados e até 5 intervalos por período;
 *  • dia com reunião fica indisponível para todos os atendimentos;
 *  • horários que o responsável pode escolher.
 * AgendamentoService, ReuniaoService e DisponibilidadeService só chamam esta classe — as regras não se repetem.
 */
final class AgendaService
{
    public const DURACAO = Tempo::SLOT_MIN; // minutos de um atendimento

    public function __construct(
        private AgendamentoRepositoryInterface $ag,
        private AlunoRepositoryInterface $alunos,
        private ReuniaoRepositoryInterface $reunioes,
        private DisponibilidadeRepositoryInterface $disp,
        private UsuarioRepositoryInterface $users
    ) {
    }

    // ---------- compromissos ----------
    /** Compromissos de uma pessoa numa data: atendimentos ativos (como orientador ou responsável) e reuniões não canceladas. */
    public function compromissosDaPessoa(string $pessoaId, string $data, ?string $ignorarAg = null, ?string $ignorarReuniao = null): array
    {
        $perfil = ($this->users->find($pessoaId)['perfil'] ?? '');
        $out = [];
        if ($perfil === Perfil::ORIENTADOR) {
            $atend = $this->ag->byOrientador($pessoaId);
        } elseif ($perfil === Perfil::RESPONSAVEL) {
            $atend = $this->ag->byResponsavel($pessoaId);
            foreach ($this->alunos->byResponsavel($pessoaId) as $aluno) {
                $atend = array_merge($atend, $this->ag->byAluno((string) $aluno['rm']));
            }
            $atend = array_values(array_reduce($atend, static function (array $acc, array $row): array { $acc[$row['id']] = $row; return $acc; }, []));
        } else {
            $atend = [];
        }
        foreach ($atend as $a) {
            if ($a['data'] === $data && in_array($a['status'], S::ATIVOS, true) && $a['id'] !== $ignorarAg) {
                $ini = Tempo::minutos($a['horario']);
                $out[] = ['tipo' => 'atendimento', 'id' => $a['id'], 'ini' => $ini, 'fim' => $ini + self::DURACAO, 'titulo' => 'atendimento'];
            }
        }
        foreach ($this->reunioes->paraUsuario($pessoaId) as $r) {
            if ($r['data'] === $data && ($r['status'] ?? '') !== 'Cancelada' && $r['id'] !== $ignorarReuniao) {
                $out[] = ['tipo' => 'reuniao', 'id' => $r['id'], 'ini' => Tempo::minutos($r['horario']), 'fim' => Tempo::minutos($r['horarioFim']), 'titulo' => 'reunião "' . $r['assunto'] . '"'];
            }
        }
        usort($out, fn ($x, $y) => $x['ini'] <=> $y['ini']);
        return $out;
    }

    public function conflitosDaPessoa(string $pessoaId, string $data, int $ini, int $fim, ?string $ignorarAg = null, ?string $ignorarReuniao = null): array
    {
        return array_values(array_filter($this->compromissosDaPessoa($pessoaId, $data, $ignorarAg, $ignorarReuniao), fn ($c) => Tempo::sobrepoe($ini, $fim, $c['ini'], $c['fim'])));
    }

    public function exigirPessoaLivre(string $pessoaId, string $data, int $ini, int $fim, ?string $ignorarAg = null, ?string $ignorarReuniao = null): void
    {
        $c = $this->conflitosDaPessoa($pessoaId, $data, $ini, $fim, $ignorarAg, $ignorarReuniao);
        if ($c) {
            $nome = $this->users->find($pessoaId)['nome'] ?? $pessoaId;
            throw HttpException::conflict(sprintf('Conflito de horário: %s já possui %s das %s às %s.', $nome, $c[0]['titulo'], Tempo::deMinutos($c[0]['ini']), Tempo::deMinutos($c[0]['fim'])),
                ['campo' => 'horario', 'pessoaId' => $pessoaId, 'conflitoCom' => $c[0]['tipo'], 'conflitoId' => $c[0]['id']]);
        }
    }

    // ---------- reuniões ocupam o dia ----------
    public function reunioesDoDia(string $data, ?string $ignorarReuniao = null): array
    {
        return array_values(array_filter($this->reunioes->all(), fn ($r) => $r['data'] === $data && ($r['status'] ?? '') !== 'Cancelada' && $r['id'] !== $ignorarReuniao));
    }

    public function diaBloqueadoPorReuniao(string $data): bool
    {
        return $this->reunioesDoDia($data) !== [];
    }

    /** Não permite criar reunião numa data que já tenha atendimento ativo; reunião torna o dia inteiro indisponível. */
    public function exigirDiaSemAtendimentos(string $data): void
    {
        foreach ($this->ag->all() as $a) {
            if (($a['data'] ?? '') === $data && in_array($a['status'] ?? '', S::ATIVOS, true)) {
                throw HttpException::conflict('Já existe atendimento agendado nesta data. Cancele ou remaneje os atendimentos antes de cadastrar uma reunião.', ['campo' => 'data', 'agendamentoId' => $a['id'] ?? null]);
            }
        }
    }

    // ---------- disponibilidade da orientadora ----------
    private function periodosDoDia(string $orientadorId, string $data): array
    {
        $dia = Tempo::diaSemana($data);
        return array_values(array_filter($this->disp->byOrientador($orientadorId), fn ($d) => $d['dia'] === $dia));
    }

    public function temDisponibilidade(string $orientadorId): bool
    {
        return (bool) $this->disp->byOrientador($orientadorId);
    }

    /** O atendimento começa dentro de um período, TERMINA antes do fim dele e não cai em nenhum intervalo (almoço, pausas)? */
    public function dentroDaDisponibilidade(string $orientadorId, string $data, string $horario): bool
    {
        $t = Tempo::minutos($horario);
        foreach ($this->periodosDoDia($orientadorId, $data) as $p) {
            if ($t < Tempo::minutos($p['inicio']) || $t + self::DURACAO > Tempo::minutos($p['fim'])) {
                continue;
            }
            $emIntervalo = false;
            foreach ($p['intervalos'] ?? [] as $i) {
                if (Tempo::sobrepoe($t, $t + self::DURACAO, Tempo::minutos($i['inicio']), Tempo::minutos($i['fim']))) {
                    $emIntervalo = true;
                    break;
                }
            }
            if (!$emIntervalo) {
                return true;
            }
        }
        return false;
    }

    /** Minutos de INÍCIO que ainda podem ser escolhidos (precisão de minuto). Já desconta intervalos, compromissos e horários passados. */
    public function minutosLivres(string $orientadorId, string $data): array
    {
        if ($data < Tempo::hoje() || $this->diaBloqueadoPorReuniao($data)) {
            return [];
        }
        $ocupados = $this->compromissosDaPessoa($orientadorId, $data);
        $agora = $data === Tempo::hoje() ? (int) date('G') * 60 + (int) date('i') : -1;
        $livres = [];
        foreach ($this->periodosDoDia($orientadorId, $data) as $p) {
            for ($t = Tempo::minutos($p['inicio']); $t + self::DURACAO <= Tempo::minutos($p['fim']); $t++) {
                if ($t <= $agora || !$this->dentroDaDisponibilidade($orientadorId, $data, Tempo::deMinutos($t))) {
                    continue;
                }
                foreach ($ocupados as $c) {
                    if (Tempo::sobrepoe($t, $t + self::DURACAO, $c['ini'], $c['fim'])) {
                        continue 2;
                    }
                }
                $livres[$t] = true;
            }
        }
        $livres = array_keys($livres);
        sort($livres);
        return $livres;
    }

    /** Faixas de horários de INÍCIO livres: [{inicio:"08:00", fim:"11:30"}, ...]. */
    public function faixasLivres(string $orientadorId, string $data): array
    {
        $faixas = [];
        foreach ($this->minutosLivres($orientadorId, $data) as $m) {
            $u = count($faixas) - 1;
            if ($u >= 0 && $m === $faixas[$u][1] + 1) {
                $faixas[$u][1] = $m;
            } else {
                $faixas[] = [$m, $m];
            }
        }
        return array_map(fn ($f) => ['inicio' => Tempo::deMinutos($f[0]), 'fim' => Tempo::deMinutos($f[1])], $faixas);
    }

    // ---------- a regra completa para marcar um atendimento ----------
    /**
     * Toda criação/alteração/aprovação de atendimento passa por aqui (e deve rodar dentro da trava da agenda).
     * $responsavelId: quando o responsável participa (solicitação), a agenda dele também é conferida.
     */
    public function exigirAtendimentoPossivel(string $orientadorId, string $alunoRm, string|array|null $responsavelId, string $data, string $horario, ?string $ignorarAg = null): void
    {
        if ($this->diaBloqueadoPorReuniao($data)) {
            throw HttpException::conflict('Há reunião marcada em ' . date('d/m/Y', strtotime($data . ' 12:00')) . ': não é possível marcar atendimentos nesta data.', ['campo' => 'data']);
        }
        if (!$this->dentroDaDisponibilidade($orientadorId, $data, $horario)) {
            throw HttpException::unprocessable(['horario' => 'Horário fora da disponibilidade do orientador (o atendimento de ' . self::DURACAO . ' min precisa caber no período e fora dos intervalos).']);
        }
        $ini = Tempo::minutos($horario);
        $fim = $ini + self::DURACAO;
        $this->exigirPessoaLivre($orientadorId, $data, $ini, $fim, $ignorarAg);
        if ($this->ag->conflitosDoAluno($alunoRm, $data, $horario, self::DURACAO, $ignorarAg)) {
            throw HttpException::conflict('Conflito de horário: o aluno já possui agendamento nesse horário.', ['campo' => 'horario']);
        }
        $responsaveis = is_array($responsavelId) ? $responsavelId : ($responsavelId ? [$responsavelId] : []);
        foreach (array_unique(array_filter($responsaveis)) as $idResponsavel) {
            $this->exigirPessoaLivre((string) $idResponsavel, $data, $ini, $fim, $ignorarAg);
        }
    }
}
