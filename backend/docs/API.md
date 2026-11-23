# API do SIGEP — rotas

> Gerado automaticamente por `php backend/bin/docs.php` a partir de `backend/routes/api.php`. Não edite à mão.

## Convenções

- Base: `/api/...`. Corpo e respostas em JSON (UTF-8).
- Sucesso: `{"ok": true, "data": ..., "meta": ...}`. Erro: `{"ok": false, "error": {"code", "message", "details"}}`.
- Autenticação: cookie de sessão `SIGEPSESS` (HttpOnly) criado em `POST /api/auth/login`. O front deve enviar `credentials: 'include'`.
- CSRF: toda rota autenticada que altera dados (POST/PUT/PATCH/DELETE) exige o cabeçalho `X-CSRF-Token` com o `csrfToken` devolvido no login ou em `GET /api/auth/me`.
- Códigos: 400 requisição inválida · 401 não autenticado · 403 sem permissão/CSRF · 404 não encontrado · 409 conflito (ex.: horário) · 422 dados inválidos · 429 muitas tentativas · 501 não implementado.
- Datas `AAAA-MM-DD`; horários `HH:MM`. Status de atendimento: Pendente, Confirmado, Em Andamento, Finalizado, Cancelado, Recusado.
- Permissão `pública` = sem login; `qualquer usuário logado` = só login; `a,b` = basta ter uma. O administrador (perfil `instituicao`) tem todas. Matriz completa em `backend/config/permissions.php`.

## Rotas

| Método | Rota | Função | Dados esperados | Resposta | Permissão |
|---|---|---|---|---|---|
| GET | `/api/health` | Verifica se a API está no ar. | — | {status, sistema, hora} | pública |
| POST | `/api/auth/login` | Login. Abre a sessão (cookie HttpOnly) e devolve o token CSRF. | email, senha | {usuario, permissoes, csrfToken} | pública |
| POST | `/api/auth/logout` | Encerra a sessão. | — | {mensagem} | qualquer usuário logado |
| GET | `/api/auth/me` | Usuário logado, permissões e token CSRF atual. | — | {usuario, permissoes, csrfToken} | qualquer usuário logado |
| GET | `/api/auth/csrf` | Token CSRF da sessão atual. | — | {csrfToken} | pública |
| POST | `/api/auth/register` | Cadastro do próprio responsável ("Cadastre-se"). Pode ser desligado no .env. | nome, email, cpf, telefone?, senha | usuário criado | pública |
| POST | `/api/auth/forgot-password` | Pede o e-mail de redefinição de senha (resposta sempre igual). | email | {mensagem} | pública |
| POST | `/api/auth/reset-password` | Define a nova senha usando o token recebido por e-mail. | token, novaSenha | {mensagem} | pública |
| GET | `/api/perfil` | Dados do próprio usuário. | — | usuário | perfil.own |
| PUT | `/api/perfil` | Atualiza nome, e-mail e telefone do próprio usuário. | nome?, email?, telefone? | usuário | perfil.own |
| PUT | `/api/perfil/senha` | Troca a própria senha. | senhaAtual, novaSenha | {mensagem} | perfil.own |
| GET | `/api/preferencias` | Preferências (tela Configurações). | — | {notifEmail, notifSistema, confirmarExclusao, conflitoHorario} | preferencias.own |
| PUT | `/api/preferencias` | Salva preferências. | qualquer chave de preferências (boolean) | preferências | preferencias.own |
| GET | `/api/lgpd/meus-dados` | LGPD: dados que o sistema guarda sobre o próprio usuário. | — | {cadastro, agendamentos, registrosDeAtividade, ...} | lgpd.own |
| GET | `/api/permissoes` | Matriz de permissões por perfil. | — | {perfil: [permissões]} | usuarios.manage |
| GET | `/api/usuarios` | Lista usuários. | query: perfil?, status?, busca? | [usuário] | usuarios.manage |
| POST | `/api/usuarios` | Cadastra usuário (sem senha: envia link para a pessoa definir). | perfil, nome, email, cpf, telefone?, senha? | usuário | usuarios.manage |
| GET | `/api/usuarios/{id}` | Consulta um usuário. | — | usuário | usuarios.manage |
| PUT | `/api/usuarios/{id}` | Edita dados do usuário. | nome?, email?, cpf?, telefone?, senha? | usuário | usuarios.manage |
| PATCH | `/api/usuarios/{id}/status` | Ativa ou desativa o usuário. | status (Ativo\|Inativo) | usuário | usuarios.manage |
| PUT | `/api/usuarios/{id}/perfil` | Altera o perfil de acesso (bloqueado se houver vínculos). | perfil | usuário | usuarios.manage |
| DELETE | `/api/usuarios/{id}` | Exclui usuário sem vínculos. | — | {mensagem} | usuarios.manage |
| GET | `/api/responsaveis` | Lista responsáveis. | query: status?, busca? | [usuário] | responsaveis.manage |
| POST | `/api/responsaveis` | Cadastra responsável. | nome, email, cpf, telefone?, senha? | usuário | responsaveis.manage |
| GET | `/api/responsaveis/{id}` | Consulta responsável. | — | usuário | responsaveis.manage |
| PUT | `/api/responsaveis/{id}` | Edita responsável. | nome?, email?, cpf?, telefone?, senha? | usuário | responsaveis.manage |
| DELETE | `/api/responsaveis/{id}` | Exclui responsável sem alunos/atendimentos. | — | {mensagem} | responsaveis.manage |
| GET | `/api/orientadores/basico` | Orientadores ativos (id, nome) para telas de escolha. | — | [{id, nome}] | orientadores.list.basic |
| GET | `/api/orientadores` | Lista orientadores. | query: status?, busca? | [usuário] | orientadores.manage |
| POST | `/api/orientadores` | Cadastra orientador. | nome, email, cpf, telefone?, senha? | usuário | orientadores.manage |
| GET | `/api/orientadores/{id}` | Consulta orientador. | — | usuário | orientadores.manage |
| PUT | `/api/orientadores/{id}` | Edita orientador. | nome?, email?, cpf?, telefone?, senha? | usuário | orientadores.manage |
| DELETE | `/api/orientadores/{id}` | Exclui orientador sem vínculos. | — | {mensagem} | orientadores.manage |
| GET | `/api/alunos` | Lista alunos (responsável vê só os seus). | query: busca?, curso?, ano?, periodo?, responsavelId? | [aluno com responsaveis[]] | alunos.read,alunos.read.own |
| POST | `/api/alunos` | Cadastra aluno com 1 ou mais responsáveis (N:N). | rm, nome, ano, curso, periodo, responsaveisIds[] | aluno | alunos.write |
| GET | `/api/alunos/{rm}` | Consulta aluno. | — | aluno | alunos.read,alunos.read.own |
| PUT | `/api/alunos/{rm}` | Edita aluno; responsaveisIds[] substitui a lista de responsáveis (adicionar/remover). | nome?, ano?, curso?, periodo?, responsaveisIds[]? | aluno | alunos.write |
| DELETE | `/api/alunos/{rm}` | Exclui aluno sem histórico. | — | {mensagem} | alunos.write |
| GET | `/api/alunos/{rm}/historico` | Histórico pedagógico (atendimentos finalizados). | — | {aluno, total, atendimentos[]} | alunos.read,alunos.read.own |
| GET | `/api/instituicao` | Dados da instituição. | — | {nome, cnpj, endereco, telefone, email} | instituicao.read |
| PUT | `/api/instituicao` | Atualiza dados da instituição. | nome, cnpj, endereco, telefone, email | {...} | instituicao.write |
| GET | `/api/disponibilidades` | Lista disponibilidades (orientador vê as suas). | query: orientadorId? | [disponibilidade] | disponibilidade.read |
| POST | `/api/disponibilidades` | Cadastra período (minutos livres) com até 5 intervalos; bloqueia sobreposição. | dia (0-6), inicio, fim, intervalos[{inicio,fim}]?, orientadorId (admin) | disponibilidade | disponibilidade.manage |
| PUT | `/api/disponibilidades/{id}` | Altera período e intervalos (bloqueia sobreposição). | dia, inicio, fim, intervalos[]? | disponibilidade | disponibilidade.manage |
| DELETE | `/api/disponibilidades/{id}` | Exclui horário. | — | {mensagem} | disponibilidade.manage |
| GET | `/api/orientadores/{id}/horarios` | Horários livres (faixas de início, precisão de minuto) do orientador numa data. Já desconta intervalos, atendimentos, reuniões e dia bloqueado por reunião. | query: data (AAAA-MM-DD) | {faixas[{inicio,fim}], temDisponibilidade, bloqueadoPorReuniao, duracaoMinutos} | disponibilidade.read |
| GET | `/api/solicitacoes` | Solicitações (Pendente e Recusado). A Instituição não tem acesso (403). | query: status?, de?, ate? | [agendamento] | agendamentos.read |
| POST | `/api/solicitacoes` | Responsável solicita atendimento (nasce Pendente). Motivo "Outro" exige descrição. | alunoRm, orientadorId, data, horario (HH:MM, qualquer minuto livre), motivo, descricao? | agendamento | solicitacoes.create |
| POST | `/api/solicitacoes/{id}/aprovar` | Só o orientador responsável aprova (vira Confirmado; reconfere agenda, disponibilidade e reuniões). | — | agendamento | solicitacoes.review |
| POST | `/api/solicitacoes/{id}/recusar` | Só o orientador responsável recusa. | motivoRecusa? | agendamento | solicitacoes.review |
| GET | `/api/agendamentos` | Lista agendamentos visíveis. situacao=andamento (Meu agendamento: Pendente/Confirmado/Em Andamento) ou historico (Finalizado/Cancelado/Recusado). A Instituição não vê solicitações. | query: situacao?, status?, de?, ate?, alunoRm?, orientadorId? | [agendamento] | agendamentos.read |
| POST | `/api/agendamentos` | Orientador/Instituição agenda direto (nasce CONFIRMADO, sem solicitação). Precisa caber na disponibilidade e não conflitar. | alunoRm, data, horario, motivo, descricao?, orientadorId (Instituição) | agendamento | agendamentos.create.direct |
| GET | `/api/agendamentos/{id}` | Consulta agendamento. | — | agendamento | agendamentos.read |
| PUT | `/api/agendamentos/{id}` | Altera data/horário/motivo/descrição. | data?, horario?, motivo?, descricao? | agendamento | agendamentos.manage,agendamentos.manage.own |
| POST | `/api/agendamentos/{id}/cancelar` | Cancela o agendamento. | motivo? | agendamento | agendamentos.manage,agendamentos.manage.own |
| PATCH | `/api/agendamentos/{id}/aviso-responsavel` | Liga/desliga o aviso ao responsável neste agendamento. | notificarResponsavel (boolean) | agendamento | agendamentos.manage |
| GET | `/api/atendimentos` | Atendimentos (Confirmado, Em Andamento, Finalizado, Cancelado). | query: status?, de?, ate?, alunoRm? | [agendamento] | atendimentos.read |
| GET | `/api/atendimentos/{id}` | Consulta atendimento. | — | agendamento | atendimentos.read |
| POST | `/api/atendimentos/{id}/iniciar` | Inicia (Confirmado → Em Andamento) e grava a hora inicial real. | — | agendamento | atendimentos.conduct |
| PUT | `/api/atendimentos/{id}/registro` | Salva descrição, observações e resultado sem finalizar. | descricao?, observacoes?, resultado? | agendamento | atendimentos.conduct |
| PUT | `/api/atendimentos/{id}/horarios` | Ajusta hora inicial/final reais. | horaInicialReal?, horaFinalReal? | agendamento | atendimentos.conduct |
| PATCH | `/api/atendimentos/{id}/visibilidade` | Orientador libera/oculta o registro (descrição e resultado) para o responsável. | registroVisivel (boolean) | agendamento | atendimentos.conduct |
| POST | `/api/atendimentos/{id}/finalizar` | Finaliza (exige descrição e resultado; máx. 3 h) e grava a hora final real. | descricao?, observacoes?, resultado?, notificarResponsavel?, registroVisivel? | agendamento | atendimentos.conduct |
| GET | `/api/reunioes/participantes` | Pessoas que podem ser convidadas. | — | [{id, nome, perfil}] | reunioes.manage |
| GET | `/api/reunioes` | Lista reuniões (situacao: Agendada\|Realizada\|Cancelada). | query: situacao? | [reunião] | reunioes.manage |
| POST | `/api/reunioes` | Cadastra reunião (compromisso real: confere a agenda de TODOS os participantes). Orientador não convida outros orientadores. | assunto, descricao?, data, horario, horarioFim, participantes[] | reunião | reunioes.manage |
| GET | `/api/reunioes/{id}` | Consulta reunião. | — | reunião | reunioes.manage |
| PUT | `/api/reunioes/{id}` | Edita reunião que ainda não terminou (criador ou Instituição); reconfere conflitos. | assunto, descricao?, data, horario, horarioFim, participantes[] | reunião | reunioes.manage |
| POST | `/api/reunioes/{id}/cancelar` | Cancela (libera a agenda; o registro permanece no histórico). | — | reunião | reunioes.manage |
| GET | `/api/notificacoes` | Notificações do usuário (mais novas primeiro). | query: cat?, lida? | [notificação] + meta.naoLidas | notificacoes.own |
| POST | `/api/notificacoes/marcar-todas` | Marca todas como lidas. | — | {marcadas} | notificacoes.own |
| PATCH | `/api/notificacoes/{id}` | Marca uma como lida/não lida. | lida (boolean) | notificação | notificacoes.own |
| GET | `/api/auditoria` | Registro de atividades. | query: usuarioId?, cat?, de?, ate?, pagina?, porPagina? | [registro] + meta.total | auditoria.read |
| GET | `/api/agenda` | Agenda de quem consulta: atendimentos E reuniões (ambos ocupam horário). Instituição: tudo, sem solicitações. | query: de?, ate?, incluirCancelados? | [{tipo: atendimento\|reuniao, data, inicio, fim, titulo, status, participantes}] | agenda.read |
| GET | `/api/orientadores/{id}/ocupacao` | Horários já ocupados do orientador numa data (atendimentos e reuniões). | query: data (AAAA-MM-DD) | {bloqueadoPorReuniao, ocupados[{tipo,inicio,fim}]} | agenda.read |
| GET | `/api/historico` | Histórico permanente. Instituição/orientador: atendimentos realizados + reuniões realizadas (campo tipo). Responsável: seus atendimentos já encerrados com os registros liberados. | query: tipo? (atendimento\|reuniao), de?, ate?, incluirCancelados? | [{tipo, data, inicio, fim, titulo, participantes, descricao?, resultado?}] | historico.read |
| GET | `/api/relatorios/resumo` | Totais por status, mês, curso e orientador. | query: de?, ate? | {total, porStatus, porMes, porCurso, porOrientador, duracaoMediaMinutos} | relatorios.read |
| GET | `/api/relatorios/atendimentos/exportar` | Exporta atendimentos em CSV ou SVG vetorial. | query: formato=csv|svg, de?, ate?, status? | arquivo CSV ou SVG | relatorios.read |
