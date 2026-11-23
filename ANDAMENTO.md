# SIGEP — estado das alterações deste pacote

## Alterações aplicadas

### Front-end (`frontend/sigep.html`)
- Responsável: **Meus Agendamentos** mostra apenas Pendente, Confirmado e Em Andamento; **Histórico** mostra Finalizado, Cancelado e Recusado, com descrição e resultado.
- Orientador: agenda mensal/semanal/diária agora reúne atendimentos e reuniões; reuniões mostram início e término e aparecem como compromissos próprios.
- Instituição: removida a área Solicitações do menu e do roteamento; adicionada área **Histórico** unificada para atendimentos e reuniões, com identificação visual por tipo.
- Removida a exibição/edição de status nas telas de cadastro de alunos, responsáveis e orientadores. O status interno de conta continua existindo no back-end para controle de acesso.
- Reunião bloqueia o dia inteiro para novos atendimentos e para aprovações; não é permitido cadastrar reunião em data com atendimento ativo.
- Validações visuais de conflito usam sobreposição real de intervalos, checam orientador, responsável, aluno e reuniões; horários fora da disponibilidade são bloqueados.
- Orientador não pode selecionar outros orientadores como participantes de reunião; somente a Instituição pode fazê-lo. A edição/cancelamento na interface respeita quem criou a reunião.
- Relatórios: exportação SVG real no navegador e impressão com navegação/controles ocultos.

### Back-end (`backend/`)
- AgendaService centraliza regras de conflitos, horários livres e bloqueio integral do dia de reunião.
- Conflitos de responsáveis consideram também os demais alunos vinculados ao responsável (N:N), e as verificações de agendamento passam a consultar todos os responsáveis vinculados ao aluno.
- Reunião não pode ser criada numa data com atendimento ativo; edição para outra data aplica a mesma regra.
- Atualização de reunião valida que o horário final não esteja no passado.
- Exportação de relatórios agora aceita `formato=csv` e `formato=svg`; CSV usa escape explícito compatível com PHP 8.3+.
- Documentação de API/relatórios atualizada; removida a opção de configuração que permitia desativar o bloqueio integral do dia.

## Verificações realizadas
- `php -l` executado em todos os 105 arquivos PHP: sem erros de sintaxe.
- A suíte automatizada não pôde ser concluída no ambiente original porque o PHP disponível não tem a extensão `mbstring` exigida pelo projeto. Em cópia temporária com verificação da extensão contornada e polyfills limitados para os testes, os testes antigos entram em conflito com a nova regra obrigatória de bloqueio do dia inteiro (eles configuravam `REUNIAO_BLOQUEIA_DIA=false` e esperavam atendimentos no mesmo dia da reunião). Esses testes precisam ser atualizados para refletir o requisito novo.
- Smoke test isolado da API (em cópia temporária com o requisito de `mbstring` contornado): login 200; exportação SVG 200 com corpo SVG; reunião criada 201; atendimento no mesmo dia bloqueado 409; reunião sobreposta para o mesmo participante bloqueada 409; cancelamento 200; atendimento após cancelamento 201.
- Smoke test isolado da API (em cópia temporária com o requisito de `mbstring` contornado): login 200; exportação SVG 200 com corpo SVG; reunião criada 201; atendimento no mesmo dia bloqueado 409; reunião sobreposta para o mesmo participante bloqueada 409; cancelamento 200; atendimento após cancelamento 201.
- O front-end é uma aplicação React/Babel carregada via CDN dentro de `sigep.html`; não foi possível executar teste de navegador completo neste ambiente. A interface continua sendo um protótipo com estado local; ela ainda não consome automaticamente todas as rotas PHP.

## Pontos que ainda exigem validação/integração
- A camada PDO/MySQL ainda não está completa para todos os repositórios. A trava atual por arquivo protege o armazenamento JSON/local, mas em produção com MySQL deve ser substituída por transação/lock no banco (`SELECT ... FOR UPDATE` ou mecanismo equivalente).
- O fluxo visual atual mantém dados de demonstração em memória; alterações não persistem após recarregar a página, até que a integração do front-end com a API seja concluída.
- Necessário executar os testes de interface no navegador com PHP 8.3, `mbstring`, `pdo_mysql` e o banco configurado; também testar concorrência com duas requisições simultâneas.
- A impressão deve ser conferida no navegador e na impressora/PDF do ambiente de apresentação.

## Ajustes adicionais da revisão de 22/11/2026
- Confirmação de agendamento pela agenda agora valida também a disponibilidade cadastrada, assim como a aprovação pela tela de solicitações; um pedido fora da faixa não pode ser confirmado.
- Agenda oculta atendimentos cancelados/recusados e reuniões canceladas. A legenda abaixo do calendário identifica pendente, confirmado, em andamento (amarelo), finalizado e reunião.
- Participantes de reunião: orientador pode selecionar somente alunos; Instituição pode selecionar alunos, responsáveis, orientadores e administradores.
- Menu da Instituição deixa de exibir as áreas separadas de Atendimentos e Reuniões; o Histórico concentra os registros. O filtro de Reuniões no histórico foi corrigido.
- Histórico reorganizado em Tipo, Registro, Data e horário, Participantes e Motivo. O motivo abre uma janela própria para leitura completa.
- Registro de atendimento passou a suportar várias sessões; o atendimento permanece Em Andamento entre sessões e só é encerrado quando o orientador aciona Encerrar atendimento.
- Exportação SVG redesenhada com dimensões maiores, barras proporcionais, cores por status, título, período e total de registros. Impressão recebeu regras para quebra de texto e tabelas dentro das margens.
- Limitação do navegador: o texto `localhost` impresso no cabeçalho/rodapé é inserido pelo próprio diálogo de impressão (opção Cabeçalhos e rodapés do Chrome), não pelo conteúdo da página. Para removê-lo, desmarque essa opção na janela de impressão.
- Verificação: `php -l` passou nos 107 arquivos PHP. A suíte de testes continua bloqueada neste ambiente pela ausência da extensão `mbstring`; o teste visual completo em navegador também não foi concluído aqui.
