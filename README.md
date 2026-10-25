# ⚽ Futebol — presença, times equilibrados e cobrança do racha

Sistema para o grupo de futebol de todo fim de semana:

1. **Presença** — cada jogador confirma por um link (sem login, só com o WhatsApp).
2. **Dia do jogo** — o admin marca quem vai chegando; o app sorteia os times **equilibrados pelo score**: os primeiros `2 × jogadores por time` a chegar formam os dois primeiros times; quem chegou depois forma os próximos (que entram no lugar de quem perder).
3. **Fim do jogo** — o admin informa o valor da quadra; o app divide, gera o **Pix copia e cola** (com o valor de cada um) pronto para o WhatsApp e controla **quem já pagou**.

Também: cadastro público de arenas, jogadores em vários grupos (score por grupo), jogo fixo semanal com “sem jogo neste dia”.

Estrutura no padrão **SOLUS** (PHP puro, MVC com o controller dentro da view, PDO, migrations em SQL, multi-tenant) e visual baseado nas **Human Interface Guidelines da Apple**.

---

## Instalação rápida (Windows)

Requisitos: **PHP 7.4+** (com `pdo_mysql`, `mbstring`) e um **MySQL/MariaDB ligado** (XAMPP, Laragon, MySQL Installer…).

```powershell
cd G:\Projetos\futebol
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\instalar.ps1
```

O script pergunta os dados do MySQL, cria o `.env`, o banco e as tabelas e abre `http://localhost:8080`.
Na primeira tela, use **Criar conta** para cadastrar o administrador.

### Instalação manual (qualquer sistema)

```bash
cp .env.example .env            # edite SERVER, PORTA, USER, PASS, BANCO
php cron/instalar.php           # cria o banco (se faltar) e roda as migrations
php -S localhost:8080 servidor.php
```

### Com Docker (sem instalar PHP nem MySQL)

```bash
docker compose up -d --build
```

Abre em **http://localhost:8090** (a 8080 fica livre). O compose sobe dois containers — `futebol-app` (PHP 8.2 + Apache) e `futebol-db` (MariaDB 10.11, dados no volume `futebol_db`) — e as tabelas são criadas sozinhas na subida.

- Outra porta: `APP_PORT=9000 docker compose up -d` (PowerShell: `$env:APP_PORT=9000; docker compose up -d`). Porta do banco para DBeaver/HeidiSQL: `127.0.0.1:3307` (usuário `futebol`, senha `futebol`, banco `futebol`; mude com `DB_PORT`).
- A pasta do projeto é montada no container: editou o código, é só recarregar a página.
- Logs: `docker compose logs -f app` · Parar: `docker compose down` (os dados ficam) · Apagar tudo, inclusive o banco: `docker compose down -v`.
- A configuração do container está em `docker/env.docker` (não usa o seu `.env`). Para produção, troque as senhas no `docker-compose.yml` e no `docker/env.docker`.

`composer install` é **opcional**: sem a pasta `vendor/` o sistema usa um autoload e um leitor de `.env` próprios. Com ele, o `vlucas/phpdotenv` assume o `.env`.

> O Bootstrap 4.6 e o jQuery vêm de CDN (jsdelivr / code.jquery.com): é preciso internet ao abrir o sistema.

## Como usar

1. **Arenas** → cadastre o local (nome, valor, endereço, link do Google Maps). É público: todos os admins usam, só quem cadastrou edita.
2. **Grupos → Novo** → nome, jogadores por time, **cores dos dois coletes**, **jogo fixo** (dia/horário/arena) e **Pix** (tipo da chave, chave, nome e cidade do recebedor).
3. **Jogadores** (aba do grupo) → nome, WhatsApp, Instagram, nascimento, quem convidou e o **score 0–10** (só o admin vê). O mesmo WhatsApp pode estar em vários grupos, com score diferente em cada um.
4. **Jogos** → com o jogo fixo ligado, o app cria as próximas 4 semanas sozinho. Em semana sem jogo, toque em **Sem jogo** (some a confirmação e o gerador não recria o dia).
5. **Antes do jogo** → copie o link do jogo (ou “Enviar no WhatsApp”) e mande no grupo. Quem não estiver cadastrado informa o nome e entra na lista com score 5 (você ajusta depois).
6. **No dia** → toque em **Chegou** na ordem em que as pessoas chegam (ou **Todos chegaram**) → **Sortear times**. O sorteio por score vale **só para a primeira formação**: os primeiros 2 × (jogadores por time) viram os dois times (um para cada colete) e quem chegou depois entra na **lista de espera**, na ordem de chegada.
7. **Partidas** → **Iniciar partida**, marque os gols em **+ Gol** (autor, gol contra ou “não sei quem fez”) e **Encerrar partida**. O vencedor fica com o colete; o perdedor vai para o **fim da lista**; o próximo time sai dos **primeiros da lista**, do primeiro para o último (quem estava de fora vem antes de quem acabou de sair; se faltar gente, completa com os primeiros de quem saiu) e veste o colete de quem saiu. **Empate:** escolha **Par ou ímpar** (o app sorteia um número de 0 a 5 para cada time; soma par ganha o par, ímpar ganha o ímpar; quem ganha fica) ou **Escolher** manualmente. Tudo (inclusive os números do par ou ímpar) fica no histórico. Errou? **Descartar** (partida iniciada por engano) ou **Reabrir** (encerrada sem querer). Chegou alguém depois do sorteio? **Entrar na lista**. O botão ⋯ ao lado do jogador muda ele de lugar na lista (primeiro, subir, descer, último) ou o tira da quadra entre uma partida e outra.
8. **Relatório** → em **Histórico da noite**, **Relatório completo**: todas as partidas (escalações, quem veio do time que saiu, gols com minuto, par ou ímpar, duração), o desempenho de **cada jogador** (partidas, V/E/D, gols, gols contra, minutos, partidas esperando, partida por partida) e um texto pronto para copiar ou enviar no WhatsApp. Também dá para imprimir.
9. **Histórico** → cada jogo guarda todas as partidas (colete e time de cada lado, quem jogou, quem veio do time que saiu, gols com minuto, duração) e os artilheiros da noite. A aba **Ranking** do grupo soma tudo: gols, vitórias, empates, derrotas e aproveitamento (sempre / este ano / 30 dias).
10. **No fim** → **Fechar jogo e cobrar**: informe o total da quadra → **Pix copia e cola** + **Enviar no WhatsApp**. Marque cada pagamento na lista (ou use **Cobrar** para mandar a cobrança individual).

## Estrutura de pastas

```
index.php            entrada única (?tela=...)
router.php           roteador dos pedidos AJAX (POST controller + acao + csrf)
servidor.php         roteador do "php -S" (desenvolvimento): bloqueia .env, model/ etc.
model/               classes (namespace Model): Conect, Init, Login, Arenas, Grupos, Jogadores,
                     Jogos, Presencas, Times, Partidas, Sorteio, Pagamentos, PixPayload, Recorrentes, Migrate
view/<modulo>/       index.php (HTML) + controller.php (controller) + javascript.js
view/layout.php      barra de navegação, abas e scripts comuns
migrations/          SQL puro: AAAA_MM_DD_HHMMSS_nome.sql (nome com "deletar" => vai p/ backup/)
ini/funcoes.php      funções globais
assets/              apple.css e app.js (alertTop, api, confirmar...)
cron/                migrate.php, instalar.php, gerar_jogos.php
tests/integracao.php teste dos models contra um MySQL de teste
```

Convenções do SOLUS mantidas: `controller` + `acao` no `router.php`, resposta em `$output` JSON, `try/catch` com `error_log`, soft delete `excluido = 's'/'n'`, `.env` com `SERVER/PORTA/USER/PASS/BANCO`, PDO com `prepare`, nunca SQL com dados concatenados.

**Multi-tenant**: o “tenant” é o administrador (`usuarios.id`). Grupos, jogadores do grupo, jogos, presenças, times e pagamentos só são acessíveis por quem é dono do grupo (toda consulta passa por `grupoDoUsuario()` / `jogoDoUsuario()`). Arenas são a exceção pública, editáveis só pelo criador. Os dados pessoais de um jogador que está em grupos de outros admins não são sobrescritos (só score, convite e situação do grupo).

## Regras importantes

- **Sorteio** (`model/Sorteio.php`): várias divisões aleatórias, cada uma melhorada por trocas até a diferença de score entre o time mais forte e o mais fraco parar de cair; entre as melhores (tolerância de 0,5) sorteia uma — equilibrado, mas diferente a cada vez. Times diferem em no máximo 1 jogador.
- **Rotação** (`model/Partidas.php`, `model/Times.php`): lado a = colete 1, lado b = colete 2. O sorteio por score só forma os dois primeiros times; quem chegou depois vai para a **lista de espera** (`jogos_fila`), na ordem de chegada. Quem ganha segue com colete e lado; quem perde sai e seus jogadores vão para o **fim da lista**, na ordem em que estavam. O próximo time = os **primeiros N da lista** (quem estava de fora vem antes; se faltar gente, completa com os primeiros de quem acabou de sair) e veste o colete livre. Empate: par ou ímpar (servidor sorteia 0–5 para cada time com `random_int`; soma par ganha o lado do par; quem ganha fica) ou escolha manual; números e resultado ficam em `jogos_partidas`. Descartar/reabrir restauram a lista exatamente. O sorteio só pode ser refeito antes da primeira partida; o jogo só fecha sem partida em andamento.
- **Relatório** (`Partidas::relatorio`, tela `?tela=relatorio&id=`): partidas com escalações, gols, duração e desempate; estatística por jogador (partidas, V/E/D, gols, gols contra, minutos, partidas esperando, partida por partida); texto para WhatsApp e impressão.
- **Pix** (`model/PixPayload.php`): BR Code estático (EMV, CRC16/CCITT-FALSE), reutilizável por todos do grupo. Nome do recebedor até 25 e cidade até 15 caracteres, sem acento. Valor por jogador = total ÷ quem jogou, **arredondado para cima** (a quadra nunca fica no prejuízo).
- **Link público**: token aleatório de 128 bits por jogo; a página pública não mostra WhatsApp de ninguém.

## Segurança

CSRF em todo POST, controllers só por lista permitida, senhas com `password_hash`, sessão com `HttpOnly` e `SameSite=Lax`, `.htaccess` (Apache) e `servidor.php` (php -S) bloqueando `.env`, `model/`, `migrations/`, `tests/`. Para produção: use HTTPS, defina `APP_URL` no `.env`, desligue `CADASTRO_ABERTO` depois de criar seus administradores e **não** exponha `cron/` pela web (o `migrate.php` só aceita web com `MIGRATE_TOKEN`).

## Testes

```bash
# usa o banco do .env — o nome PRECISA conter "teste" (ele apaga todos os dados!)
php tests/integracao.php
```

Cobre Pix (CRC), sorteio, login, arenas, grupos, jogadores, jogos fixos, presenças, ordem de chegada, sorteio, fechamento, pagamentos e o isolamento entre administradores e as partidas (coletes, fila, empréstimo, gols, descartar/reabrir, ranking) — 178 verificações.

## Agendamento (opcional)

O app já cria os jogos fixos quando o admin abre a tela inicial ou o grupo. Se quiser garantir mesmo sem acesso: `php cron/gerar_jogos.php` (Agendador de Tarefas do Windows ou cron).

## Créditos

Ícones: [Feather Icons](https://feathericons.com) (MIT) e uma bola de futebol própria.
