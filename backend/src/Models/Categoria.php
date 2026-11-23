<?php
declare(strict_types=1);

namespace Sigep\Models;

/** Categorias usadas na auditoria e nas notificações. */
final class Categoria
{
    public const AUTENTICACAO = 'Autenticação';
    public const USUARIOS = 'Usuários';
    public const PERMISSOES = 'Permissões';
    public const ALUNOS = 'Alunos';
    public const SOLICITACOES = 'Solicitações';
    public const AGENDAMENTOS = 'Agendamentos';
    public const ATENDIMENTOS = 'Atendimentos';
    public const REUNIOES = 'Reuniões';
    public const DISPONIBILIDADE = 'Disponibilidade';
    public const INSTITUICAO = 'Instituição';
}
