<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\Validator;
use Sigep\Services\LgpdService;
use Sigep\Services\UsuarioService;
use Sigep\Support\Presenter;

/** Dados da própria conta: perfil, senha, preferências (tela Configurações) e acesso do titular (LGPD). */
final class PerfilController extends Controller
{
    public function __construct(private UsuarioService $svc, private LgpdService $lgpd)
    {
    }

    public function show(Request $r): Response { $u = $this->user($r); return $this->ok(Presenter::usuario($u, $u)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizarProprioPerfil($this->user($r), $r->body)); }

    public function senha(Request $r): Response
    {
        $d = Validator::validate($r->body, ['senhaAtual' => 'required|string|max:128', 'novaSenha' => 'required|string|max:128']);
        $this->svc->trocarSenha($this->user($r), $d['senhaAtual'], $d['novaSenha']);
        return $this->ok(['mensagem' => 'Senha alterada.']);
    }

    public function preferencias(Request $r): Response { return $this->ok($this->svc->preferencias($this->user($r))); }
    public function salvarPreferencias(Request $r): Response { return $this->ok($this->svc->salvarPreferencias($this->user($r), $r->body)); }
    public function meusDados(Request $r): Response { return $this->ok($this->lgpd->meusDados($this->user($r))); }
}
