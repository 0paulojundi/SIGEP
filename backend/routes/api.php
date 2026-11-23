<?php
declare(strict_types=1);

use Sigep\Controllers\{AgendaController, AgendamentoController, AlunoController, AtendimentoController, AuditoriaController, AuthController, DisponibilidadeController,
    InstituicaoController, NotificacaoController, OrientadorController, PerfilController, RelatorioController, ReuniaoController,
    ResponsavelController, SistemaController, SolicitacaoController, UsuarioController};
use Sigep\Core\Router;

/**
 * Tabela de rotas da API. Cada linha: método, caminho, [Controller, método], permissão, resumo, entrada, saída.
 *   permissão null  → rota pública
 *   permissão ''    → qualquer usuário logado
 *   permissão 'x'   → precisa da permissão x (config/permissions.php); 'a,b' = basta uma
 * Rotas autenticadas que alteram dados também exigem o cabeçalho X-CSRF-Token.
 * A documentação (docs/API.md) é gerada daqui: php bin/docs.php
 */
return function (Router $router): void {
    $r = function (string $m, string $p, array $h, ?string $perm, string $resumo, string $in = '—', string $out = '—') use ($router): void {
        $mw = [];
        if ($perm !== null) {
            $mw[] = 'auth';
            if (!in_array($m, ['GET', 'HEAD'], true)) {
                $mw[] = 'csrf';
            }
            if ($perm !== '') {
                $mw[] = 'perm:' . $perm;
            }
        }
        $router->add($m, $p, $h, $mw, ['resumo' => $resumo, 'entrada' => $in, 'saida' => $out,
            'permissao' => $perm === null ? 'pública' : ($perm === '' ? 'qualquer usuário logado' : $perm)]);
    };

    // ---------- sistema e autenticação ----------
    $r('GET', '/api/health', [SistemaController::class, 'health'], null, 'Verifica se a API está no ar.', '—', '{status, sistema, hora}');
    $r('POST', '/api/auth/login', [AuthController::class, 'login'], null, 'Login. Abre a sessão (cookie HttpOnly) e devolve o token CSRF.', 'email, senha', '{usuario, permissoes, csrfToken}');
    $r('POST', '/api/auth/logout', [AuthController::class, 'logout'], '', 'Encerra a sessão.', '—', '{mensagem}');
    $r('GET', '/api/auth/me', [AuthController::class, 'me'], '', 'Usuário logado, permissões e token CSRF atual.', '—', '{usuario, permissoes, csrfToken}');
    $r('GET', '/api/auth/csrf', [AuthController::class, 'csrf'], null, 'Token CSRF da sessão atual.', '—', '{csrfToken}');
    $r('POST', '/api/auth/register', [AuthController::class, 'registrar'], null, 'Cadastro do próprio responsável ("Cadastre-se"). Pode ser desligado no .env.', 'nome, email, cpf, telefone?, senha', 'usuário criado');
    $r('POST', '/api/auth/forgot-password', [AuthController::class, 'esqueciSenha'], null, 'Pede o e-mail de redefinição de senha (resposta sempre igual).', 'email', '{mensagem}');
    $r('POST', '/api/auth/reset-password', [AuthController::class, 'redefinirSenha'], null, 'Define a nova senha usando o token recebido por e-mail.', 'token, novaSenha', '{mensagem}');

    // ---------- conta própria ----------
    $r('GET', '/api/perfil', [PerfilController::class, 'show'], 'perfil.own', 'Dados do próprio usuário.', '—', 'usuário');
    $r('PUT', '/api/perfil', [PerfilController::class, 'update'], 'perfil.own', 'Atualiza nome, e-mail e telefone do próprio usuário.', 'nome?, email?, telefone?', 'usuário');
    $r('PUT', '/api/perfil/senha', [PerfilController::class, 'senha'], 'perfil.own', 'Troca a própria senha.', 'senhaAtual, novaSenha', '{mensagem}');
    $r('GET', '/api/preferencias', [PerfilController::class, 'preferencias'], 'preferencias.own', 'Preferências (tela Configurações).', '—', '{notifEmail, notifSistema, confirmarExclusao, conflitoHorario}');
    $r('PUT', '/api/preferencias', [PerfilController::class, 'salvarPreferencias'], 'preferencias.own', 'Salva preferências.', 'qualquer chave de preferências (boolean)', 'preferências');
    $r('GET', '/api/lgpd/meus-dados', [PerfilController::class, 'meusDados'], 'lgpd.own', 'LGPD: dados que o sistema guarda sobre o próprio usuário.', '—', '{cadastro, agendamentos, registrosDeAtividade, ...}');

    // ---------- usuários e permissões (administrador) ----------
    $r('GET', '/api/permissoes', [UsuarioController::class, 'permissoes'], 'usuarios.manage', 'Matriz de permissões por perfil.', '—', '{perfil: [permissões]}');
    $r('GET', '/api/usuarios', [UsuarioController::class, 'index'], 'usuarios.manage', 'Lista usuários.', 'query: perfil?, status?, busca?', '[usuário]');
    $r('POST', '/api/usuarios', [UsuarioController::class, 'store'], 'usuarios.manage', 'Cadastra usuário (sem senha: envia link para a pessoa definir).', 'perfil, nome, email, cpf, telefone?, senha?', 'usuário');
    $r('GET', '/api/usuarios/{id}', [UsuarioController::class, 'show'], 'usuarios.manage', 'Consulta um usuário.', '—', 'usuário');
    $r('PUT', '/api/usuarios/{id}', [UsuarioController::class, 'update'], 'usuarios.manage', 'Edita dados do usuário.', 'nome?, email?, cpf?, telefone?, senha?', 'usuário');
    $r('PATCH', '/api/usuarios/{id}/status', [UsuarioController::class, 'status'], 'usuarios.manage', 'Ativa ou desativa o usuário.', 'status (Ativo|Inativo)', 'usuário');
    $r('PUT', '/api/usuarios/{id}/perfil', [UsuarioController::class, 'perfil'], 'usuarios.manage', 'Altera o perfil de acesso (bloqueado se houver vínculos).', 'perfil', 'usuário');
    $r('DELETE', '/api/usuarios/{id}', [UsuarioController::class, 'destroy'], 'usuarios.manage', 'Exclui usuário sem vínculos.', '—', '{mensagem}');

    // ---------- responsáveis e orientadores (administrador) ----------
    $r('GET', '/api/responsaveis', [ResponsavelController::class, 'index'], 'responsaveis.manage', 'Lista responsáveis.', 'query: status?, busca?', '[usuário]');
    $r('POST', '/api/responsaveis', [ResponsavelController::class, 'store'], 'responsaveis.manage', 'Cadastra responsável.', 'nome, email, cpf, telefone?, senha?', 'usuário');
    $r('GET', '/api/responsaveis/{id}', [ResponsavelController::class, 'show'], 'responsaveis.manage', 'Consulta responsável.', '—', 'usuário');
    $r('PUT', '/api/responsaveis/{id}', [ResponsavelController::class, 'update'], 'responsaveis.manage', 'Edita responsável.', 'nome?, email?, cpf?, telefone?, senha?', 'usuário');
    $r('DELETE', '/api/responsaveis/{id}', [ResponsavelController::class, 'destroy'], 'responsaveis.manage', 'Exclui responsável sem alunos/atendimentos.', '—', '{mensagem}');
    $r('GET', '/api/orientadores/basico', [OrientadorController::class, 'basico'], 'orientadores.list.basic', 'Orientadores ativos (id, nome) para telas de escolha.', '—', '[{id, nome}]');
    $r('GET', '/api/orientadores', [OrientadorController::class, 'index'], 'orientadores.manage', 'Lista orientadores.', 'query: status?, busca?', '[usuário]');
    $r('POST', '/api/orientadores', [OrientadorController::class, 'store'], 'orientadores.manage', 'Cadastra orientador.', 'nome, email, cpf, telefone?, senha?', 'usuário');
    $r('GET', '/api/orientadores/{id}', [OrientadorController::class, 'show'], 'orientadores.manage', 'Consulta orientador.', '—', 'usuário');
    $r('PUT', '/api/orientadores/{id}', [OrientadorController::class, 'update'], 'orientadores.manage', 'Edita orientador.', 'nome?, email?, cpf?, telefone?, senha?', 'usuário');
    $r('DELETE', '/api/orientadores/{id}', [OrientadorController::class, 'destroy'], 'orientadores.manage', 'Exclui orientador sem vínculos.', '—', '{mensagem}');

    // ---------- alunos ----------
    $r('GET', '/api/alunos', [AlunoController::class, 'index'], 'alunos.read,alunos.read.own', 'Lista alunos (responsável vê só os seus).', 'query: busca?, curso?, ano?, periodo?, responsavelId?', '[aluno com responsaveis[]]');
    $r('POST', '/api/alunos', [AlunoController::class, 'store'], 'alunos.write', 'Cadastra aluno com 1 ou mais responsáveis (N:N).', 'rm, nome, ano, curso, periodo, responsaveisIds[]', 'aluno');
    $r('GET', '/api/alunos/{rm}', [AlunoController::class, 'show'], 'alunos.read,alunos.read.own', 'Consulta aluno.', '—', 'aluno');
    $r('PUT', '/api/alunos/{rm}', [AlunoController::class, 'update'], 'alunos.write', 'Edita aluno; responsaveisIds[] substitui a lista de responsáveis (adicionar/remover).', 'nome?, ano?, curso?, periodo?, responsaveisIds[]?', 'aluno');
    $r('DELETE', '/api/alunos/{rm}', [AlunoController::class, 'destroy'], 'alunos.write', 'Exclui aluno sem histórico.', '—', '{mensagem}');
    $r('GET', '/api/alunos/{rm}/historico', [AlunoController::class, 'historico'], 'alunos.read,alunos.read.own', 'Histórico pedagógico (atendimentos finalizados).', '—', '{aluno, total, atendimentos[]}');

    // ---------- instituição ----------
    $r('GET', '/api/instituicao', [InstituicaoController::class, 'show'], 'instituicao.read', 'Dados da instituição.', '—', '{nome, cnpj, endereco, telefone, email}');
    $r('PUT', '/api/instituicao', [InstituicaoController::class, 'update'], 'instituicao.write', 'Atualiza dados da instituição.', 'nome, cnpj, endereco, telefone, email', '{...}');

    // ---------- disponibilidade ----------
    $r('GET', '/api/disponibilidades', [DisponibilidadeController::class, 'index'], 'disponibilidade.read', 'Lista disponibilidades (orientador vê as suas).', 'query: orientadorId?', '[disponibilidade]');
    $r('POST', '/api/disponibilidades', [DisponibilidadeController::class, 'store'], 'disponibilidade.manage', 'Cadastra período (minutos livres) com até 5 intervalos; bloqueia sobreposição.', 'dia (0-6), inicio, fim, intervalos[{inicio,fim}]?, orientadorId (admin)', 'disponibilidade');
    $r('PUT', '/api/disponibilidades/{id}', [DisponibilidadeController::class, 'update'], 'disponibilidade.manage', 'Altera período e intervalos (bloqueia sobreposição).', 'dia, inicio, fim, intervalos[]?', 'disponibilidade');
    $r('DELETE', '/api/disponibilidades/{id}', [DisponibilidadeController::class, 'destroy'], 'disponibilidade.manage', 'Exclui horário.', '—', '{mensagem}');
    $r('GET', '/api/orientadores/{id}/horarios', [DisponibilidadeController::class, 'horarios'], 'disponibilidade.read', 'Horários livres (faixas de início, precisão de minuto) do orientador numa data. Já desconta intervalos, atendimentos, reuniões e dia bloqueado por reunião.', 'query: data (AAAA-MM-DD)', '{faixas[{inicio,fim}], temDisponibilidade, bloqueadoPorReuniao, duracaoMinutos}');

    // ---------- solicitações ----------
    $r('GET', '/api/solicitacoes', [SolicitacaoController::class, 'index'], 'agendamentos.read', 'Solicitações (Pendente e Recusado). A Instituição não tem acesso (403).', 'query: status?, de?, ate?', '[agendamento]');
    $r('POST', '/api/solicitacoes', [SolicitacaoController::class, 'store'], 'solicitacoes.create', 'Responsável solicita atendimento (nasce Pendente). Motivo "Outro" exige descrição.', 'alunoRm, orientadorId, data, horario (HH:MM, qualquer minuto livre), motivo, descricao?', 'agendamento');
    $r('POST', '/api/solicitacoes/{id}/aprovar', [SolicitacaoController::class, 'aprovar'], 'solicitacoes.review', 'Só o orientador responsável aprova (vira Confirmado; reconfere agenda, disponibilidade e reuniões).', '—', 'agendamento');
    $r('POST', '/api/solicitacoes/{id}/recusar', [SolicitacaoController::class, 'recusar'], 'solicitacoes.review', 'Só o orientador responsável recusa.', 'motivoRecusa?', 'agendamento');

    // ---------- agendamentos ----------
    $r('GET', '/api/agendamentos', [AgendamentoController::class, 'index'], 'agendamentos.read', 'Lista agendamentos visíveis. situacao=andamento (Meu agendamento: Pendente/Confirmado/Em Andamento) ou historico (Finalizado/Cancelado/Recusado). A Instituição não vê solicitações.', 'query: situacao?, status?, de?, ate?, alunoRm?, orientadorId?', '[agendamento]');
    $r('POST', '/api/agendamentos', [AgendamentoController::class, 'store'], 'agendamentos.create.direct', 'Orientador/Instituição agenda direto (nasce CONFIRMADO, sem solicitação). Precisa caber na disponibilidade e não conflitar.', 'alunoRm, data, horario, motivo, descricao?, orientadorId (Instituição)', 'agendamento');
    $r('GET', '/api/agendamentos/{id}', [AgendamentoController::class, 'show'], 'agendamentos.read', 'Consulta agendamento.', '—', 'agendamento');
    $r('PUT', '/api/agendamentos/{id}', [AgendamentoController::class, 'update'], 'agendamentos.manage,agendamentos.manage.own', 'Altera data/horário/motivo/descrição.', 'data?, horario?, motivo?, descricao?', 'agendamento');
    $r('POST', '/api/agendamentos/{id}/cancelar', [AgendamentoController::class, 'cancelar'], 'agendamentos.manage,agendamentos.manage.own', 'Cancela o agendamento.', 'motivo?', 'agendamento');
    $r('PATCH', '/api/agendamentos/{id}/aviso-responsavel', [AgendamentoController::class, 'avisoResponsavel'], 'agendamentos.manage', 'Liga/desliga o aviso ao responsável neste agendamento.', 'notificarResponsavel (boolean)', 'agendamento');

    // ---------- atendimentos ----------
    $r('GET', '/api/atendimentos', [AtendimentoController::class, 'index'], 'atendimentos.read', 'Atendimentos (Confirmado, Em Andamento, Finalizado, Cancelado).', 'query: status?, de?, ate?, alunoRm?', '[agendamento]');
    $r('GET', '/api/atendimentos/{id}', [AtendimentoController::class, 'show'], 'atendimentos.read', 'Consulta atendimento.', '—', 'agendamento');
    $r('POST', '/api/atendimentos/{id}/iniciar', [AtendimentoController::class, 'iniciar'], 'atendimentos.conduct', 'Inicia (Confirmado → Em Andamento) e grava a hora inicial real.', '—', 'agendamento');
    $r('PUT', '/api/atendimentos/{id}/registro', [AtendimentoController::class, 'registro'], 'atendimentos.conduct', 'Salva descrição, observações e resultado sem finalizar.', 'descricao?, observacoes?, resultado?', 'agendamento');
    $r('PUT', '/api/atendimentos/{id}/horarios', [AtendimentoController::class, 'horarios'], 'atendimentos.conduct', 'Ajusta hora inicial/final reais.', 'horaInicialReal?, horaFinalReal?', 'agendamento');
    $r('PATCH', '/api/atendimentos/{id}/visibilidade', [AtendimentoController::class, 'visibilidade'], 'atendimentos.conduct', 'Orientador libera/oculta o registro (descrição e resultado) para o responsável.', 'registroVisivel (boolean)', 'agendamento');
    $r('POST', '/api/atendimentos/{id}/finalizar', [AtendimentoController::class, 'finalizar'], 'atendimentos.conduct', 'Finaliza (exige descrição e resultado; máx. 3 h) e grava a hora final real.', 'descricao?, observacoes?, resultado?, notificarResponsavel?, registroVisivel?', 'agendamento');

    // ---------- reuniões ----------
    $r('GET', '/api/reunioes/participantes', [ReuniaoController::class, 'participantes'], 'reunioes.manage', 'Pessoas que podem ser convidadas.', '—', '[{id, nome, perfil}]');
    $r('GET', '/api/reunioes', [ReuniaoController::class, 'index'], 'reunioes.manage', 'Lista reuniões (situacao: Agendada|Realizada|Cancelada).', 'query: situacao?', '[reunião]');
    $r('POST', '/api/reunioes', [ReuniaoController::class, 'store'], 'reunioes.manage', 'Cadastra reunião (compromisso real: confere a agenda de TODOS os participantes). Orientador não convida outros orientadores.', 'assunto, descricao?, data, horario, horarioFim, participantes[]', 'reunião');
    $r('GET', '/api/reunioes/{id}', [ReuniaoController::class, 'show'], 'reunioes.manage', 'Consulta reunião.', '—', 'reunião');
    $r('PUT', '/api/reunioes/{id}', [ReuniaoController::class, 'update'], 'reunioes.manage', 'Edita reunião que ainda não terminou (criador ou Instituição); reconfere conflitos.', 'assunto, descricao?, data, horario, horarioFim, participantes[]', 'reunião');
    $r('POST', '/api/reunioes/{id}/cancelar', [ReuniaoController::class, 'cancelar'], 'reunioes.manage', 'Cancela (libera a agenda; o registro permanece no histórico).', '—', 'reunião');

    // ---------- notificações ----------
    $r('GET', '/api/notificacoes', [NotificacaoController::class, 'index'], 'notificacoes.own', 'Notificações do usuário (mais novas primeiro).', 'query: cat?, lida?', '[notificação] + meta.naoLidas');
    $r('POST', '/api/notificacoes/marcar-todas', [NotificacaoController::class, 'marcarTodas'], 'notificacoes.own', 'Marca todas como lidas.', '—', '{marcadas}');
    $r('PATCH', '/api/notificacoes/{id}', [NotificacaoController::class, 'marcar'], 'notificacoes.own', 'Marca uma como lida/não lida.', 'lida (boolean)', 'notificação');

    // ---------- auditoria e relatórios ----------
    $r('GET', '/api/auditoria', [AuditoriaController::class, 'index'], 'auditoria.read', 'Registro de atividades.', 'query: usuarioId?, cat?, de?, ate?, pagina?, porPagina?', '[registro] + meta.total');
    // ---------- agenda, ocupação e histórico unificados ----------
    $r('GET', '/api/agenda', [AgendaController::class, 'agenda'], 'agenda.read', 'Agenda de quem consulta: atendimentos E reuniões (ambos ocupam horário). Instituição: tudo, sem solicitações.', 'query: de?, ate?, incluirCancelados?', '[{tipo: atendimento|reuniao, data, inicio, fim, titulo, status, participantes}]');
    $r('GET', '/api/orientadores/{id}/ocupacao', [AgendaController::class, 'ocupacao'], 'agenda.read', 'Horários já ocupados do orientador numa data (atendimentos e reuniões).', 'query: data (AAAA-MM-DD)', '{bloqueadoPorReuniao, ocupados[{tipo,inicio,fim}]}');
    $r('GET', '/api/historico', [AgendaController::class, 'historico'], 'historico.read', 'Histórico permanente. Instituição/orientador: atendimentos realizados + reuniões realizadas (campo tipo). Responsável: seus atendimentos já encerrados com os registros liberados.', 'query: tipo? (atendimento|reuniao), de?, ate?, incluirCancelados?', '[{tipo, data, inicio, fim, titulo, participantes, descricao?, resultado?}]');

    $r('GET', '/api/relatorios/resumo', [RelatorioController::class, 'resumo'], 'relatorios.read', 'Totais por status, mês, curso e orientador.', 'query: de?, ate?', '{total, porStatus, porMes, porCurso, porOrientador, duracaoMediaMinutos}');
    $r('GET', '/api/relatorios/atendimentos/exportar', [RelatorioController::class, 'exportar'], 'relatorios.read', 'Exporta atendimentos em CSV ou SVG vetorial.', 'query: formato=csv|svg, de?, ate?, status?', 'arquivo CSV ou SVG');
};
