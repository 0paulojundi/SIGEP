# SIGEP — Sistema Integrado de Gestão Educacional e Pedagógica

Projeto com **front-end PHP** (`index.php` → `frontend/index.php`) e **back-end em PHP 8** (`backend/`), uma API REST em JSON.

> **Estado atual (leia primeiro):** esta entrega foi preparada para a gravação do vídeo em **modo de demonstração sem banco**. A interface PHP funciona com dados temporários no navegador (`sessionStorage`). O back-end PHP/API permanece no projeto, mas o protótipo visual ainda não está conectado a ele nem ao MySQL. Veja a seção [Limitações](#limitações-e-o-que-ainda-falta).

---

## 1. Estrutura

```text
SIGEP/
├── index.php                     entrada principal PHP
├── frontend/index.php            interface SIGEP servida pelo PHP
├── frontend/sigep.html           redirecionamento legado
├── backend/
│   ├── index.php                 ponto de entrada da API
│   ├── bootstrap.php             autoload (sem Composer) + leitura do .env
│   ├── dev-router.php            servidor de desenvolvimento (front + API juntos)
│   ├── .htaccess                 Apache/InfinityFree: reescrita para index.php + bloqueios
│   ├── config/                   app.php (listas), permissions.php (matriz de permissões), database.php (PDO)
│   ├── routes/api.php            TABELA DE ROTAS (76 rotas) — a documentação é gerada daqui
│   ├── src/
│   │   ├── Core/                 Request, Response, Router, Container, Validator, Sessão, Kernel
│   │   ├── Controllers/          só traduzem HTTP ⇄ serviços
│   │   ├── Services/             REGRAS DE NEGÓCIO (conflitos, status, permissões por registro, notificações, auditoria…)
│   │   ├── Models/                constantes: Perfil, StatusAtendimento, Categoria
│   │   ├── Middleware/           Auth (sessão), Csrf, Permission
│   │   ├── Repositories/
│   │   │   ├── Contracts/        INTERFACES de persistência (o que os serviços conhecem)
│   │   │   ├── Store/            implementação TEMPORÁRIA (arquivos JSON)
│   │   │   └── Pdo/              base PDO + 1 exemplo (PdoUsuarioRepository) — onde o MySQL entra
│   │   ├── Storage/              armazenamento temporário (JsonFileStore, ArrayStore)
│   │   ├── Database/Connection.php   conexão PDO (só abre com STORAGE_DRIVER=pdo)
│   │   ├── Mail/                 MailerInterface, LogMailer, NativeMailer
│   │   ├── Seed/DemoData.php     dados de demonstração
│   │   └── App.php               monta tudo — PONTO DE TROCA da persistência
│   ├── bin/                      seed.php (recria dados demo) · docs.php (gera docs/API.md)
│   ├── tests/                    run.php (246 verificações) · http_smoke.php (63 verificações pela rede)
│   ├── docs/API.md               rotas documentadas (gerado) · docs/TESTES.md guia de testes
│   └── storage/                  data/ logs/ sessions/  (bloqueados na web)
├── .env.example                  modelo de configuração
└── README.md
```

Fluxo de uma requisição: `Kernel → Middleware (auth, csrf, permissão) → Controller → Service → Repository (interface) → Store temporário / futuro PDO`.

## Modo de demonstração sem banco

Nesta versão, o front-end guarda cadastros, atendimentos, reuniões, disponibilidades e alterações em `sessionStorage` do navegador. Os dados continuam disponíveis durante a mesma aba, inclusive se a página for atualizada. Ao fechar a aba ou a janela do navegador, o navegador descarta esses dados automaticamente. Uma nova aba começa com os dados iniciais de demonstração. Não há gravação permanente, sincronização entre dispositivos nem integração do protótipo com a API.

A logo do SIGEP está incorporada diretamente no código como imagem Base64, então não depende de um arquivo de imagem externo separado. Ícones são fornecidos pela biblioteca Lucide carregada no navegador.

## 2. Como executar (desenvolvimento)

Requisitos: **PHP 8.0 ou superior** (testado no 8.3). Sem Composer, sem frameworks, sem banco.

```bash
php -S localhost:8000 -t . backend/dev-router.php      # inicia o servidor PHP local
```

* SIGEP: <http://localhost:8000/>
* Alternativa: <http://localhost:8000/frontend/index.php>
* A API não é utilizada pelo protótipo nesta demonstração. Para abrir apenas o site, não é necessário configurar o banco nem o arquivo `.env`..

### Instalando o PHP no Windows (passo a passo resumido)

1. Instale o **Microsoft Visual C++ Redistributable 2015–2022 (x64)** (<https://aka.ms/vs/17/release/vc_redist.x64.exe>), se ainda não tiver.
2. Em <https://windows.php.net/download/> baixe o **Zip** da linha **"VS17 x64 Non Thread Safe"** (ou "VS16" no caso do PHP 8.3). O back-end foi testado no **PHP 8.3**; 8.4 e 8.5 devem funcionar, mas não foram testados.
3. Extraia o zip em `C:\php` (o arquivo `C:\php\php.exe` deve existir).
4. Em `C:\php`, copie `php.ini-development` para `php.ini`, abra no Bloco de Notas e tire o `;` do começo das linhas `;extension_dir = "ext"` e `;extension=mbstring`. Salve. **Sem `mbstring` o SIGEP avisa e não inicia.**
5. Coloque `C:\php` no **Path** (Windows → "Editar as variáveis de ambiente do sistema" → Variáveis de Ambiente → Path → Novo). Abra um terminal **novo** e confira com `php -v` e `php -m` (a lista deve ter `mbstring`).
6. Na pasta do SIGEP, execute `php -S localhost:8000 -t . backend/dev-router.php` e abra `http://localhost:8000/` no navegador. Para esta demonstração, não precisa criar `.env` nem banco de dados.

Problemas comuns: *"php não é reconhecido"* → terminal antigo ou Path errado (ou use `C:\php\php.exe` completo); *`VCRUNTIME140.dll` ausente* → passo 1; *porta ocupada* → troque `8000` por `8080`; *erro 500* → veja `backend\storage\logs\app.log` (com `APP_DEBUG=true` no `.env` o erro aparece na resposta).

**Contas de demonstração** (a senha de **todas** é o valor de `DEMO_PASSWORD` no seu `.env`; não existe senha no código):

| Perfil | E-mail |
|---|---|
| Administrador | `instituicao@sigep.com` · `coordenacao@sigep.com` |
| Orientador | `orientador@sigep.com` (Prof. Marcelo) · `orientador2@sigep.com` … `orientador5@sigep.com` |
| Responsável | `responsavel@sigep.com` (3 alunos) · `responsavel2@sigep.com` … `responsavel8@sigep.com` |

Exemplo de uso da API (cookie de sessão + CSRF):

```js
const API = 'http://localhost:8000/api';
const login = await fetch(`${API}/auth/login`, { method: 'POST', credentials: 'include',
  headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'orientador@sigep.com', senha: '...' }) });
const { csrfToken } = (await login.json()).data;
// toda operação que ALTERA dados envia o token no cabeçalho:
await fetch(`${API}/solicitacoes/A0010/aprovar`, { method: 'POST', credentials: 'include', headers: { 'X-CSRF-Token': csrfToken } });
```

## 3. Testes

```bash
php backend/tests/run.php          # 246 verificações das regras de negócio (sem servidor e sem banco)
php backend/tests/http_smoke.php   # 63 verificações PELA REDE: liga o servidor sozinho, usa cookie e CSRF reais
```

Os dois rodam no Windows, sem bash, curl ou python. **Guia completo — o que cada teste cobre, cenários passo a passo no PowerShell e checklist manual:** [`backend/docs/TESTES.md`](backend/docs/TESTES.md).

## 4. API

Rotas completas (método, caminho, função, dados esperados, resposta e permissão): **[`backend/docs/API.md`](backend/docs/API.md)** — gerado por `php backend/bin/docs.php` a partir de `backend/routes/api.php`.

Convenções: sucesso `{"ok":true,"data":…,"meta":…}` · erro `{"ok":false,"error":{"code","message","details"}}` · códigos 400/401/403/404/409 (conflito)/422 (validação)/429/501.

### Como o front se encaixa (mapa de telas → rotas)

| Tela do front | Rotas principais |
|---|---|
| Login / Cadastre-se / Esqueci a senha | `POST /auth/login` · `/auth/register` · `/auth/forgot-password` · `/auth/reset-password` |
| Dashboard / Relatórios | `GET /relatorios/resumo` · `/relatorios/atendimentos/exportar` |
| Alunos · Responsáveis · Orientadores | `/alunos` · `/responsaveis` · `/orientadores` (+ `/orientadores/basico`) |
| Usuários e Permissões | `/usuarios` · `/usuarios/{id}/status` · `/usuarios/{id}/perfil` · `/permissoes` |
| Solicitar Atendimento (responsável) | `GET /orientadores/{id}/horarios?data=…` · `POST /solicitacoes` |
| Solicitações (orientador/admin) | `GET /solicitacoes` · `POST /solicitacoes/{id}/aprovar` · `/recusar` |
| Agendamentos / Novo atendimento | `/agendamentos` (GET, POST direto, PUT, `/cancelar`) |
| Atendimentos | `/atendimentos` · `/iniciar` · `/registro` · `/horarios` · `/finalizar` · `GET /alunos/{rm}/historico` |
| Disponibilidade · Reuniões · Auditoria · Notificações | `/disponibilidades` · `/reunioes` · `/auditoria` · `/notificacoes` |
| Perfil · Configurações | `/perfil` · `/perfil/senha` · `/preferencias` · `/lgpd/meus-dados` |

Pontos a ajustar no front **quando for integrar** (a API segue o modelo dele, com estas diferenças):

* O array `atendimentos` do front corresponde a `/agendamentos` (mesma entidade, status Pendente → Confirmado → Em Andamento → Finalizado/Cancelado/Recusado).
* Notificações: o campo que o front chama `responsavelId` (destinatário) é `usuarioId` na API.
* Senha: a API exige **mínimo de 8 caracteres com letras e números** (`PASSWORD_MIN_LENGTH` no `.env`); o front hoje aceita 6. Ajuste a validação do front ou o `.env`.
* CPF: a API valida os **dígitos verificadores** (RF10). Os CPFs/CNPJ de exemplo do front não passam; o seed usa números válidos.
* O front deve usar `credentials: 'include'` e enviar `X-CSRF-Token` nas operações que alteram dados.
* O link de redefinição de senha aponta para `PASSWORD_RESET_URL` (por padrão `…/sigep.html#redefinir-senha/{token}`); essa tela ainda precisa existir no front.

## 5. Regras de negócio implementadas (resumo)

* **Perfis e permissões** — administrador (`instituicao`), orientador e responsável. A matriz fica em `config/permissions.php`; toda rota exige permissão e os serviços ainda conferem o dono do registro (orientador só vê/mexe no que é dele; responsável só vê os próprios alunos e agendamentos). O sistema nunca fica sem administrador ativo.
* **Solicitação → agendamento → atendimento** — o responsável solicita (Pendente); orientador/administrador aprova ou recusa; orientador/administrador também **agenda direto**, sem solicitação (nasce Confirmado). Atendimento: Confirmado → Em Andamento (grava hora inicial real) → Finalizado (exige descrição e resultado; grava hora final real).
* **Conflito de horários** — orientador com atendimento ativo a menos de 30 min do horário (409) e aluno com dois agendamentos ao mesmo tempo (409). Aprovar uma solicitação confere o conflito de novo.
* **Disponibilidade** — janelas por dia da semana, sem sobreposição; horários livres em blocos de 30 min (`GET /orientadores/{id}/horarios`); solicitações do responsável só passam dentro da disponibilidade.
* **Status automático** (`AUTO_STATUS`) — solicitação pendente cujo horário já passou vira Cancelado; agendamento Confirmado que **não for iniciado em 10 minutos** de atraso vira Cancelado (`ATRASO_CANCELA_MINUTOS`, 0 desliga). Cancelar manualmente não tem prazo.
* **Duração do atendimento** — padrão de 30 min na agenda; o tempo real é limitado a **3 h** (`ATENDIMENTO_MAX_MINUTOS`). Ao finalizar, o sistema respeita as horas reais que o orientador já ajustou.
* **O orientador decide** (ao finalizar ou depois) se **avisa o responsável** (`notificarResponsavel`) e se o **registro fica visível** a ele (`registroVisivel`: descrição e resultado; as observações são sempre internas).
* **Quem vê o quê** — administrador e orientador consultam todos os atendimentos (`ORIENTADOR_VE_TODOS=true`, padrão); o orientador **só age** (aprovar, iniciar, finalizar, cancelar) nos próprios. Com `false`, ele vê apenas os dele (mais restritivo para a LGPD).
* **Reuniões** — 1 ou mais participantes, edição/cancelamento só de reuniões futuras, histórico (Realizada/Cancelada).
* **Notificações e auditoria** — geradas pelo servidor em cada ação (quem fez não é notificado); o orientador pode desligar o aviso ao responsável num agendamento; a preferência `notifSistema` do usuário é respeitada. A auditoria guarda **quem, o quê e quando** (e o IP nos eventos de login/logout), e nunca o conteúdo pedagógico.
* **Validação no servidor** — e-mail, CPF/CNPJ (dígitos verificadores), telefone, datas, horários, listas (curso, ano, período, motivo).

## 6. Segurança e LGPD

* Senhas com `password_hash()`/`password_verify()` (com rehash automático); política de senha configurável.
* Sessão com cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` em HTTPS), ID renovado no login, expiração por inatividade (30 min) e por tempo total (8 h). O usuário é relido a cada requisição (desativar/trocar perfil vale na hora).
* **CSRF**: token por sessão no cabeçalho `X-CSRF-Token` + checagem de `Origin`. CORS fechado por padrão (`CORS_ALLOWED_ORIGINS` para liberar outra origem).
* **Força bruta**: 5 falhas por e-mail+IP em 15 min bloqueiam (429); respostas de login/recuperação não revelam se o e-mail existe.
* Redefinição de senha: token aleatório de 256 bits, **só o hash é guardado**, vale 60 min e uma única vez.
* Cabeçalhos de segurança, erros 500 genéricos (detalhes só no log) e `.htaccess` bloqueando `storage/`, `src/`, `config/` e `.env`.
* **Proteção contra SQL Injection**: a futura camada PDO usa *prepared statements* reais (`ATTR_EMULATE_PREPARES=false`) e nomes de coluna de uma lista fixa.
* **LGPD (preparação)**: minimização (CPF mascarado para quem não é dono nem administrador; listas de escolha só com id e nome); anotações pedagógicas (`observacoes`, e `descricao` de atendimentos em andamento/finalizados) **não vão para o responsável**; auditoria sem conteúdo sensível; direito de acesso do titular em `GET /lgpd/meus-dados`. *Ainda faltam, por serem decisões da instituição:* política de retenção/eliminação, base legal documentada e canal do encarregado (DPO).

## 7. Conectando o MySQL (quando o banco estiver pronto)

A regra de negócio só conhece as **interfaces** de `src/Repositories/Contracts`. Para trocar a persistência **sem mexer nos serviços**:

1. **Crie o banco e as tabelas** (não incluídos nesta etapa). Cada coleção temporária vira uma tabela:
   `usuarios`, `alunos`, `instituicao`, `disponibilidades`, `agendamentos`, `reunioes` (+ participantes), `notificacoes`, `auditoria`, `password_resets`, `login_attempts`. Os campos estão nos arrays gravados pelos serviços (ex.: `grep -n "create(\[" -r backend/src/Services`) e no `DemoData.php`.
2. **Preencha o `.env`**: `STORAGE_DRIVER=pdo`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` e `SEED_DEMO=false`.
3. **Implemente os repositórios PDO.** Já existem a base `Repositories/Pdo/PdoRepository.php` (CRUD com prepared statements) e o exemplo completo `PdoUsuarioRepository.php`. Para cada um dos outros 9, crie `Pdo<Nome>Repository` no mesmo molde, declarando `$table`, `$columns` (campo do array → coluna) e os métodos específicos da interface correspondente:

| Interface (`Contracts/`) | Classe a criar (`Pdo/`) | Métodos específicos |
|---|---|---|
| `AlunoRepositoryInterface` | `PdoAlunoRepository` | `byResponsavel` |
| `InstituicaoRepositoryInterface` | `PdoInstituicaoRepository` | `get`, `save` |
| `DisponibilidadeRepositoryInterface` | `PdoDisponibilidadeRepository` | `byOrientador` |
| `AgendamentoRepositoryInterface` | `PdoAgendamentoRepository` | `byOrientador`, `byResponsavel`, `byAluno`, `conflitosDoOrientador`, `conflitosDoAluno` |
| `ReuniaoRepositoryInterface` | `PdoReuniaoRepository` | `paraUsuario` (participantes em tabela de ligação) |
| `NotificacaoRepositoryInterface` | `PdoNotificacaoRepository` | `doUsuario`, `marcarTodasComoLidas` |
| `AuditoriaRepositoryInterface` | `PdoAuditoriaRepository` | `registrar`, `buscar` |
| `PasswordResetRepositoryInterface` | `PdoPasswordResetRepository` | `findByHash`, `invalidarDoUsuario` |
| `LoginThrottleRepositoryInterface` | `PdoLoginThrottleRepository` | `tentativasRecentes`, `registrar`, `limpar` |

   O `App.php` já aponta para esses nomes de classe: enquanto uma delas não existir, o sistema avisa com `TODO(DB): crie …`. Todos os pontos pendentes estão marcados — `grep -rn "TODO(DB)" backend`.
4. **Valide**: rode `php backend/tests/run.php` trocando o `ArrayStore` por um banco de testes (ou adapte os testes), pois eles definem o comportamento esperado de cada repositório.
5. **Aposente o temporário**: com tudo em PDO, `Storage/` e `Repositories/Store/` deixam de ser usados e podem ser removidos.

> A camada PDO foi escrita **sem banco para testar**; trate-a como ponto de partida e valide cada consulta.

## 8. Publicação (InfinityFree / hospedagem compartilhada)

* PHP 8.0+ (escolha a versão no painel). Envie `backend/` e `frontend/`; o `backend/.htaccess` já faz a reescrita para `index.php` (a API fica em `https://seu-site/backend/api/...`).
* Coloque o `.env` **fora da pasta pública** (`htdocs`) e aponte para ele com a variável `SIGEP_ENV_FILE`; se não for possível, deixe-o na raiz — o `.htaccess` da raiz bloqueia o acesso — e confirme abrindo `https://seu-site/.env` (deve dar 403).
* Em produção: `APP_ENV=production`, `APP_DEBUG=false`, `SEED_DEMO=false`, `MAIL_DRIVER=native`, `APP_URL` e `PASSWORD_RESET_URL` com o seu domínio, HTTPS ligado.
* A pasta `backend/storage/` precisa ser gravável pelo PHP (sessões e logs).

## Limitações e o que ainda falta

**Funciona e está testado:** tudo que está nas seções 5 e 6, com dados temporários.

**Alinhamento pendente entre o front-end e o back-end** (o front já foi atualizado; o back-end ainda segue o modelo anterior):
* **Horários por minuto e intervalos:** o front agora oferece ao responsável qualquer minuto dentro dos períodos da orientadora (fora dos até 5 intervalos por período). O back-end ainda calcula blocos de 30 min e não conhece intervalos — falta ajustar `DisponibilidadeService` (campo `intervalos`, validação sem sobreposição) e a resposta de `GET /orientadores/{id}/horarios` (devolver faixas livres em vez de blocos).
* **Vários responsáveis por aluno (N:N):** o front usa vínculos aluno ⇄ responsável (modelo da tabela `responsavel_aluno`). O back-end ainda guarda um único `responsavelId` por aluno — falta a tabela/coleção de vínculos, `AlunoService` e as regras de visibilidade e notificação por vínculo.
* **Motivo "Outro":** adicionar `Outro` à lista `motivos` de `config/app.php` e exigir `descricao` nesse caso.
* **Troca de senha:** a tela do front só valida os campos; o back-end já tem a rota (`PUT /api/perfil/senha`) esperando a integração.

**Não implementado / pendente:**
* **Banco de dados**: não foi conectado. Os dados do protótipo ficam apenas no `sessionStorage` da aba e não servem como persistência de produção.
* **Front-end/API**: a interface PHP ainda não está ligada à API; as operações são simuladas no navegador. A API exige a extensão `mbstring` do PHP para funcionar neste ambiente.
* **E-mail**: só há envio de recuperação/definição de senha (`log` em desenvolvimento, `native` em produção). O envio de e-mail das demais notificações e o SMTP autenticado **não** foram implementados (a preferência `notifEmail` é guardada, mas ainda não usada).
* **Relatórios**: resumo e exportação **CSV e SVG**. PDF e XLSX ainda não foram implementados.
* **Modelo de dados**: solicitação, agendamento e atendimento compartilham um registro (como no front); a separação em 3 tabelas do DER fica para a modelagem do banco. Multi-instituição (RN013) não foi feito: há uma instituição só.
* **Importação de alunos** (planilha) e o perfil **Suporte** não foram feitos: o pedido listou três perfis e o cadastro de alunos é manual (só administrador).
* **Reunião** é uma entidade própria (como no front). Na monografia ela aparece como um Agendamento sem aluno e com "tipo"; unifique na modelagem do banco, se preferir.
* **Padrões adotados que você pode mudar:** só se inicia atendimento no dia marcado (ou depois); responsável só altera solicitação ainda Pendente; observações do orientador nunca vão ao responsável.
* **Sessões em arquivo** e bloqueio de login no armazenamento temporário: com o banco, mova o controle de tentativas para a tabela `login_attempts`.
* Auditoria é "append-only" por convenção (a interface não tem alterar/apagar), mas nada impede um administrador de banco de editar linhas — avalie proteção adicional em produção.

### Impressão
O layout de impressão oculta a navegação e ajusta as tabelas e textos longos para respeitar as margens. Para não imprimir `localhost` no cabeçalho/rodapé, abra as opções de impressão do Chrome e desmarque **Cabeçalhos e rodapés**; esse texto é inserido pelo próprio navegador e não pode ser removido pelo CSS da página.
