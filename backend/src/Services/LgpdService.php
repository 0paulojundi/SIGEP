<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\AuditoriaRepositoryInterface;
use Sigep\Support\Presenter;

/**
 * Preparação para a LGPD: direito de acesso do titular (art. 18, II).
 * Devolve os dados que o sistema guarda sobre a própria pessoa — sem hash de senha e sem anotações pedagógicas internas.
 */
final class LgpdService
{
    public function __construct(
        private AlunoRepositoryInterface $alunos,
        private AgendamentoService $ag,
        private AuditoriaRepositoryInterface $auditoria,
        private UsuarioService $usuarios
    ) {
    }

    public function meusDados(array $ator): array
    {
        $dados = [
            'geradoEm' => date('c'),
            'finalidade' => 'Gestão dos atendimentos de orientação educacional da instituição.',
            'cadastro' => Presenter::usuario($ator, $ator),
            'preferencias' => $this->usuarios->preferencias($ator),
            'agendamentos' => $this->ag->listar($ator, []),
            'registrosDeAtividade' => array_slice($this->auditoria->buscar(['usuarioId' => $ator['id']]), 0, 200),
        ];
        if ($ator['perfil'] === Perfil::RESPONSAVEL) {
            $dados['alunosVinculados'] = array_map(fn ($a) => Presenter::aluno($a, [$ator]), $this->alunos->byResponsavel($ator['id']));
        }
        return $dados;
    }
}
