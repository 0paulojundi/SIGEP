# Guia de testes do back-end do SIGEP

Há três jeitos de testar, do mais rápido ao mais "na mão". Comece pelo 1 e 2: se passarem, o back-end está funcionando.

| # | Como | Quanto demora | O que prova |
|---|---|---|---|
| 1 | `php backend\tests\run.php` | ~2 s | 246 verificações das regras de negócio (sem servidor, sem banco) |
| 2 | `php backend\tests\http_smoke.php` | ~10 s | 63 verificações **pela rede**, com cookie de sessão e CSRF de verdade |
| 3 | Testes manuais (seção 4) | você decide | você vê cada função funcionando e entende o fluxo |

> Os dois testes automáticos criam e apagam os próprios dados temporários. Não precisa ter o servidor aberto: o teste 2 liga um servidor sozinho (porta 8765; se estiver ocupada, use `php backend\tests\http_smoke.php 9000`).

## 1. Antes de começar

1. PHP instalado e funcionando (`php -v` mostra a versão e `php -m` lista `mbstring`).
2. Abra o PowerShell **dentro da pasta do SIGEP**: no Explorador de Arquivos, `Shift` + botão direito num espaço vazio → **Abrir janela do PowerShell aqui**.

## 2. Teste automático completo — `run.php`

```
php backend\tests\run.php
```

Saída esperada (resumo): cada linha começa com `✓` (passou) ou `✗` (falhou) e termina com

```
Resultado: 246/246 verificações passaram.
Tudo certo.
```

Se alguma linha tiver `✗`, ela mostra o que falhou. O teste termina com código de erro quando algo falha.

### O que ele cobre (por função do back-end)

| Função | O que é verificado |
|---|---|
| **Leitura do `.env`** | comentários na mesma linha, valores entre aspas, números e booleanos |
| **Dados de demonstração** | usuários/alunos/atendimentos criados, CPFs e CNPJ válidos, senhas guardadas só como hash |
| **Login, logout e sessão** | senha errada/e-mail inexistente (mesma resposta), login por perfil, sessão ociosa expira, logout, **bloqueio após 5 falhas (429)**, CSRF ausente/errado (403), origem diferente (403) |
| **Recuperação de senha** | e-mail enviado com token, token só guarda o hash, senha fraca recusada, token usado uma única vez, e-mail desconhecido não revela nada |
| **Cadastro público** | responsável se cadastra; e-mail repetido, CPF inválido e senha fraca são recusados |
| **Permissões** | orientador e responsável barrados nas rotas de administrador; responsável só vê os próprios alunos; orientador vê só o que a regra permite |
| **Usuários (administrador)** | cadastro, e-mail/CPF duplicado, editar, desativar/reativar, trocar perfil (bloqueado com vínculos), excluir (bloqueado com vínculos), não desativar a si mesmo |
| **Alunos e responsáveis** | cadastro, RM duplicado, curso inválido, responsável obrigatório e ativo, trocar responsável, excluir (bloqueado com histórico) |
| **Instituição** | CNPJ inválido recusado |
| **Disponibilidade** | janelas sobrepostas (409), janelas encostadas permitidas, horário final antes do inicial, editar/excluir, cálculo de horários livres em blocos de 30 min |
| **Solicitações e agendamentos** | solicitar, aprovar, recusar com motivo, **conflito de horário (409)**, aluno em dois lugares, data no passado, horário fora da disponibilidade, agendamento direto, alterar, cancelar, solicitação vencida cancelada sozinha |
| **Regras do SIGEP** | atraso de 10 min cancela, duração máxima de 3 h, orientador decide avisar o responsável e liberar o registro, modo "orientador vê todos" |
| **Atendimentos** | iniciar (hora real), salvar registro, ajustar horas, finalizar (exige descrição e resultado), histórico do aluno, responsável sem acesso às anotações internas |
| **Reuniões** | cadastro com participantes, participante inexistente/passado recusados, quem vê, quem edita, cancelar, histórico (Realizada) |
| **Notificações** | contador de não lidas, marcar lida/não lida/todas, filtro por categoria, preferência `notifSistema` respeitada |
| **Auditoria** | registra quem/o quê/quando para cada ação, filtros (usuário, tipo, período), não guarda conteúdo pedagógico nem senha |
| **Relatórios e LGPD** | resumo por status/mês/curso/orientador, exportação CSV/SVG, "meus dados" sem hash, CPF mascarado |

## 3. Teste pela rede — `http_smoke.php`

```
php backend\tests\http_smoke.php
```

Ele liga o servidor sozinho, entra como **administrador, orientador e responsável de verdade** e percorre o fluxo completo (cookie `HttpOnly`, token CSRF, bloqueio de arquivos internos como `/.env`, permissões, solicitar → aprovar → conflito → agendar direto → iniciar → finalizar → histórico → auditoria → CSV/SVG). No fim:

```
Resultado: 63/63 verificações passaram.
Tudo certo.
```

## 4. Testes manuais, passo a passo (PowerShell)

Use **duas janelas** do PowerShell na pasta do SIGEP.

**Janela 1 — servidor** (deixe aberta):
```
copy .env.example .env
php -S localhost:8000 -t . backend/dev-router.php
```

**Janela 2 — seus testes.** Primeiro cole este "kit" (ele define comandos curtos para falar com a API):

```powershell
$base = 'http://localhost:8000/api'
$senha = 'Sigep@Demo2026'     # troque se mudou DEMO_PASSWORD no .env

function Entrar($email) {
  $script:sessao = New-Object Microsoft.PowerShell.Commands.WebRequestSession
  $corpo = @{ email = $email; senha = $senha } | ConvertTo-Json
  $r = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json; charset=utf-8' -Body $corpo -WebSession $script:sessao
  $script:csrf = $r.data.csrfToken
  $r.data.usuario | Select-Object nome, perfilRotulo
}
function Api($metodo, $caminho, $corpo = $null) {
  $p = @{ Uri = "$base$caminho"; Method = $metodo; WebSession = $script:sessao; ContentType = 'application/json; charset=utf-8'; Headers = @{ 'X-CSRF-Token' = $script:csrf } }
  if ($corpo) { $p.Body = ($corpo | ConvertTo-Json -Depth 5) }
  try { (Invoke-RestMethod @p).data }
  catch { Write-Host ("ERRO " + [int]$_.Exception.Response.StatusCode + ": " + $_.ErrorDetails.Message) -ForegroundColor Yellow }
}
function ProximoDia($diaSemana) {   # 0=domingo, 1=segunda ... 6=sábado
  $d = (Get-Date).Date.AddDays(2); while ([int]$d.DayOfWeek -ne $diaSemana) { $d = $d.AddDays(1) }; $d.ToString('yyyy-MM-dd')
}
```

> Os comandos abaixo seguem exatamente o que os testes automáticos fazem (e que passam). Eu não tenho um Windows aqui para rodar o PowerShell, então, se algum comando der erro de sintaxe, me mande a mensagem exata.

### Cenário A — Solicitar, aprovar e o conflito de horário

```powershell
Entrar 'responsavel@sigep.com'                       # Marcos Souza (3 alunos)
Api GET '/alunos' | Select-Object rm, nome
$seg = ProximoDia 1                                  # próxima segunda-feira
(Api GET "/orientadores/O001/horarios?data=$seg").horarios      # 08:00 … 11:30
$s1 = Api POST '/solicitacoes' @{ alunoRm='20250008'; orientadorId='O001'; data=$seg; horario='09:00'; motivo='Orientação de TCC'; descricao='Preciso de ajuda' }
$s1.status                                           # Pendente

Entrar 'responsavel2@sigep.com'                      # outro responsável pede o MESMO horário
$s2 = Api POST '/solicitacoes' @{ alunoRm='20250001'; orientadorId='O001'; data=$seg; horario='09:00'; motivo='Orientação vocacional' }

Entrar 'orientador@sigep.com'                        # Prof. Marcelo
Api GET '/solicitacoes' | Select-Object id, alunoNome, data, horario, status
(Api POST "/solicitacoes/$($s1.id)/aprovar").status  # Confirmado
Api POST "/solicitacoes/$($s2.id)/aprovar"           # ← deve mostrar ERRO 409 (conflito de horário)
(Api POST "/solicitacoes/$($s2.id)/recusar" @{ motivoRecusa='Horário ocupado' }).status   # Recusado
```
**Esperado:** o 1º vira `Confirmado`; aprovar o 2º dá **ERRO 409**; recusar funciona.

### Cenário B — Agendar direto, sem solicitação

```powershell
$ter = ProximoDia 2
$d = Api POST '/agendamentos' @{ alunoRm='20250003'; data=$ter; horario='13:30'; motivo='Acompanhamento pedagógico' }
$d.status; $d.origem                                 # Confirmado ; direta
Api POST '/agendamentos' @{ alunoRm='20250005'; data=$ter; horario='13:30'; motivo='Orientação de TCC' }   # ERRO 409
```

### Cenário C — Conduzir um atendimento

O seed cria o atendimento **A0007** para o horário em que os dados foram gerados, da Profa. Juliana (aluno Henrique, responsável Patrícia). Como existe a regra do atraso de 10 min, **gere os dados de novo logo antes deste cenário**: `php backend\bin\seed.php`.


```powershell
Entrar 'orientador2@sigep.com'
(Api POST '/atendimentos/A0007/iniciar').status                  # Em Andamento
Api POST '/atendimentos/A0007/finalizar' @{ observacoes='nota' } # ERRO 422: falta descrição e resultado
(Api POST '/atendimentos/A0007/finalizar' @{ descricao='Conversa sobre frequência.'; resultado='Plano combinado.'; observacoes='Nota interna.' }).status   # Finalizado

Entrar 'responsavel8@sigep.com'                      # Patrícia (responsável do Henrique)
Api GET '/atendimentos/A0007'                        # NÃO traz descricao/resultado/observacoes (orientador não liberou)
Entrar 'orientador2@sigep.com'
Api PATCH '/atendimentos/A0007/visibilidade' @{ registroVisivel=$true } | Out-Null
Entrar 'responsavel8@sigep.com'
Api GET '/atendimentos/A0007'                        # agora traz descricao e resultado — nunca as observações
```
> Se o horário de A0007 já passou há mais de 10 min quando você listar agendamentos, o sistema o **cancela sozinho** (regra do atraso). Para testar de novo: `php backend\bin\seed.php`.

### Cenário D — Permissões e segurança

```powershell
Entrar 'orientador@sigep.com'
Api GET '/usuarios'                                  # ERRO 403 (só administrador)
Entrar 'responsavel@sigep.com'
Api GET '/alunos/20250001'                           # ERRO 404 (aluno de outro responsável)
$csrfSalvo = $script:csrf; $script:csrf = ''
Api POST '/notificacoes/marcar-todas'                # ERRO 403 (sem token CSRF)
$script:csrf = $csrfSalvo

# bloqueio por tentativas: erre a senha 6 vezes
1..6 | ForEach-Object { try { Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' -Body '{"email":"orientador5@sigep.com","senha":"errada12345"}' } catch { [int]$_.Exception.Response.StatusCode } }
# esperado: 401 401 401 401 401 e depois 429
```

### Cenário E — Administrador: usuários, auditoria, relatórios

```powershell
Entrar 'instituicao@sigep.com'
Api POST '/usuarios' @{ perfil='orientador'; nome='Prof. Teste'; email='teste@sigep.com'; cpf='529.982.247-25' }   # cria; o e-mail de "defina sua senha" vai para backend\storage\logs\mail.log
Api POST '/usuarios' @{ perfil='orientador'; nome='Outro'; email='teste@sigep.com'; cpf='111.444.777-35' }        # ERRO 422 (e-mail repetido)
Api GET '/auditoria?porPagina=10' | Select-Object quando, usuario, acao        # tudo o que você fez aparece aqui
(Api GET '/relatorios/resumo').porStatus
Invoke-WebRequest -Uri "$base/relatorios/atendimentos/exportar?formato=csv" -WebSession $script:sessao -OutFile atendimentos.csv   # abra no Excel
```

### Ver o e-mail de redefinição de senha

Em desenvolvimento nenhum e-mail sai de verdade: ele é gravado em `backend\storage\logs\mail.log`.
```powershell
Invoke-RestMethod -Uri "$base/auth/forgot-password" -Method Post -ContentType 'application/json' -Body '{"email":"responsavel3@sigep.com"}'
Get-Content backend\storage\logs\mail.log -Tail 1       # contém o link com o token
```

## 5. Checklist para marcar à mão

| Teste | Resultado esperado |
|---|---|
| Entrar com senha errada | 401, mensagem genérica |
| 6 senhas erradas seguidas | 6ª tentativa → 429 |
| Orientador abrindo `/usuarios` | 403 |
| Responsável abrindo aluno de outro | 404 |
| POST sem token CSRF | 403 |
| Solicitar horário fora da disponibilidade | 422 |
| Dois pedidos no mesmo horário, aprovar o 2º | 409 |
| Agendar direto no mesmo horário de outro | 409 |
| Iniciar atendimento de data futura | 422 |
| Finalizar sem descrição/resultado | 422 |
| Disponibilidade sobreposta (seg 10:00–11:00 dentro de 08:00–12:00) | 409 |
| Cadastrar usuário com CPF inválido | 422 |
| Desativar a própria conta | 422 |
| Exportar relatório em PDF | 501 (ainda não implementado) |
| Abrir `http://localhost:8000/.env` no navegador | 403 (nunca deve mostrar o arquivo) |

## 6. Problemas comuns

| O que aparece | O que fazer |
|---|---|
| `php não é reconhecido` | terminal antigo ou Path errado: abra um terminal novo |
| `falta a extensão PHP 'mbstring'` | ative `extension=mbstring` no `php.ini` |
| Teste de rede: "Não consegui subir o servidor" | porta ocupada: `php backend\tests\http_smoke.php 9000` |
| Um cenário manual deu 401 | a sessão expira em 30 min sem uso, ou você esqueceu o `Entrar` |
| `Entrar` deu erro 429 | muitas tentativas erradas; espere 15 min ou apague `backend\storage\data\login_attempts.json` |
| Atendimento A0007 aparece como Cancelado | passou de 10 min do horário (regra do atraso); rode `php backend\bin\seed.php` |
