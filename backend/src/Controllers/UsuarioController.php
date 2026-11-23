<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\Validator;
use Sigep\Services\PermissionService;
use Sigep\Services\UsuarioService;

/** /api/usuarios — gestão de usuários e permissões. ResponsavelController e OrientadorController reaproveitam com perfil fixo. */
class UsuarioController extends Controller
{
    protected ?string $perfil = null;

    public function __construct(protected UsuarioService $svc, protected PermissionService $perms)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $this->perfil, $r->query)); }
    public function show(Request $r): Response { return $this->ok($this->svc->obter($this->user($r), $r->params['id'], $this->perfil)); }
    public function store(Request $r): Response { return $this->created($this->svc->criar($this->user($r), $r->body, $this->perfil)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->params['id'], $r->body, $this->perfil)); }
    public function destroy(Request $r): Response { $this->svc->excluir($this->user($r), $r->params['id'], $this->perfil); return $this->ok(['mensagem' => 'Excluído.']); }

    public function status(Request $r): Response
    {
        $d = Validator::validate($r->body, ['status' => 'required|in:Ativo,Inativo']);
        return $this->ok($this->svc->alterarStatus($this->user($r), $r->params['id'], $d['status'], $this->perfil));
    }

    public function perfil(Request $r): Response
    {
        $d = Validator::validate($r->body, ['perfil' => 'required|string']);
        return $this->ok($this->svc->alterarPerfil($this->user($r), $r->params['id'], $d['perfil']));
    }

    public function permissoes(Request $r): Response { return $this->ok($this->perms->matriz()); }
}
