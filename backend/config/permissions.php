<?php
declare(strict_types=1);

/**
 * Matriz de permissões por perfil (RF09). '*' = tudo.
 * As rotas exigem uma permissão (routes/api.php) e os serviços ainda conferem o "dono" do registro
 * (ex.: orientador só mexe nos próprios atendimentos; responsável só vê os próprios alunos).
 */
return [
    'instituicao' => ['*'],
    'orientador' => [
        'alunos.read', 'orientadores.list.basic',
        'disponibilidade.read', 'disponibilidade.manage',
        'solicitacoes.review', 'agendamentos.read', 'agendamentos.create.direct', 'agendamentos.manage',
        'atendimentos.read', 'atendimentos.conduct',
        'reunioes.manage',
        'notificacoes.own', 'relatorios.read', 'instituicao.read',
        'perfil.own', 'preferencias.own', 'lgpd.own', 'agenda.read', 'historico.read',
    ],
    'responsavel' => [
        'alunos.read.own', 'orientadores.list.basic',
        'disponibilidade.read',
        'solicitacoes.create', 'agendamentos.read', 'agendamentos.manage.own',
        'atendimentos.read',
        'notificacoes.own', 'instituicao.read',
        'perfil.own', 'preferencias.own', 'lgpd.own', 'agenda.read', 'historico.read',
    ],
];
