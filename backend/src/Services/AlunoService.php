<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\Config;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Presenter;

/**
 * Alunos e seus responsáveis — relação MUITOS-PARA-MUITOS (um aluno com 2+ responsáveis; um responsável com vários alunos).
 * Aqui os vínculos ficam em "responsaveisIds"; no banco viram a tabela intermediária responsavel_aluno (id_usuario ⇄ rm_aluno).
 * Aluno não tem "status": só entra no sistema quem de fato vai usar o SIGEP (cadastro individual, conforme a necessidade).
 */
final class AlunoService
{
    public function __construct(
        private AlunoRepositoryInterface $alunos,
        private UsuarioRepositoryInterface $users,
        private AgendamentoRepositoryInterface $agendamentos,
        private AuditService $audit,
        private Config $cfg
    ) {
    }

    public function listar(array $ator, array $q): array
    {
        $rows = $ator['perfil'] === Perfil::RESPONSAVEL ? $this->alunos->byResponsavel($ator['id']) : $this->alunos->all();
        foreach (['curso', 'ano', 'periodo'] as $f) {
            if (!empty($q[$f])) {
                $rows = array_filter($rows, fn ($a) => $a[$f] === $q[$f]);
            }
        }
        if (!empty($q['responsavelId'])) {
            $rows = array_filter($rows, fn ($a) => in_array($q['responsavelId'], $a['responsaveisIds'] ?? [], true));
        }
        if (!empty($q['busca'])) {
            $b = mb_strtolower((string) $q['busca']);
            $rows = array_filter($rows, fn ($a) => str_contains(mb_strtolower($a['nome'] . ' ' . $a['rm']), $b));
        }
        usort($rows, fn ($a, $b) => strcmp($a['nome'], $b['nome']));
        return array_map(fn ($a) => $this->apresentar($a), array_values($rows));
    }

    public function obter(array $ator, string $rm): array
    {
        return $this->apresentar($this->visivel($ator, $rm));
    }

    /** Devolve o aluno se o ator puder vê-lo; caso contrário, 404 (não revela que existe). */
    public function visivel(array $ator, string $rm): array
    {
        $a = $this->alunos->find($rm);
        if (!$a || ($ator['perfil'] === Perfil::RESPONSAVEL && !in_array($ator['id'], $a['responsaveisIds'] ?? [], true))) {
            throw HttpException::notFound('Aluno não encontrado.');
        }
        return $a;
    }

    public function criar(array $ator, array $in): array
    {
        $d = $this->validar($in, true);
        if ($this->alunos->find($d['rm'])) {
            throw HttpException::unprocessable(['rm' => 'Já existe aluno com este RM.']);
        }
        $d['responsaveisIds'] = $this->exigirResponsaveis($d['responsaveisIds']);
        $a = $this->alunos->create($d + ['id' => $d['rm'], 'criadoEm' => date('c')]);
        $this->audit->log($ator, "Cadastrou o aluno {$a['nome']} (RM {$a['rm']}) com " . count($a['responsaveisIds']) . ' responsável(is)', Categoria::ALUNOS);
        return $this->apresentar($a);
    }

    public function atualizar(array $ator, string $rm, array $in): array
    {
        $antes = $this->visivel($ator, $rm);
        $d = $this->validar($in, false);
        unset($d['rm']);
        if (isset($d['responsaveisIds'])) {
            $d['responsaveisIds'] = $this->exigirResponsaveis($d['responsaveisIds']);
        }
        $a = $this->alunos->update($rm, $d);
        $extra = isset($d['responsaveisIds']) && $d['responsaveisIds'] !== ($antes['responsaveisIds'] ?? []) ? ' (responsáveis alterados)' : '';
        $this->audit->log($ator, "Alterou dados do aluno {$a['nome']} (RM {$rm})$extra", Categoria::ALUNOS);
        return $this->apresentar($a);
    }

    public function excluir(array $ator, string $rm): void
    {
        $a = $this->visivel($ator, $rm);
        if ($this->agendamentos->byAluno($rm)) {
            throw HttpException::conflict('O aluno possui histórico de atendimentos e não pode ser excluído (o histórico é permanente).');
        }
        $this->alunos->delete($rm);
        $this->audit->log($ator, "Excluiu o aluno {$a['nome']} (RM {$rm})", Categoria::ALUNOS);
    }

    private function validar(array $in, bool $criar): array
    {
        $req = $criar ? 'required|' : '';
        $d = Validator::validate($in, [
            'rm' => $req . 'string|min:4|max:12', 'nome' => $req . 'string|min:3|max:120',
            'ano' => $req . 'in:' . implode(',', $this->cfg->get('anos')),
            'curso' => $req . 'in:' . implode(',', $this->cfg->get('cursos')),
            'periodo' => $req . 'in:' . implode(',', $this->cfg->get('periodos')),
            'responsaveisIds' => $req . 'array|min:1',
        ]);
        if (isset($d['rm']) && !ctype_digit($d['rm'])) {
            throw HttpException::unprocessable(['rm' => 'O RM deve conter apenas números.']);
        }
        return $d;
    }

    /** Cada vínculo precisa ser um usuário ATIVO com perfil Responsável (todos os perfis ficam na mesma tabela de usuários). */
    private function exigirResponsaveis(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!$ids) {
            throw HttpException::unprocessable(['responsaveisIds' => 'Selecione ao menos um responsável.']);
        }
        foreach ($ids as $id) {
            $r = $this->users->find($id);
            if (!$r || $r['perfil'] !== Perfil::RESPONSAVEL || $r['status'] !== 'Ativo') {
                throw HttpException::unprocessable(['responsaveisIds' => "Responsável inválido ou inativo: $id"]);
            }
        }
        return $ids;
    }

    private function apresentar(array $a): array
    {
        $resps = array_values(array_filter(array_map(fn ($id) => $this->users->find($id), $a['responsaveisIds'] ?? [])));
        return Presenter::aluno($a, $resps);
    }
}
