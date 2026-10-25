<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Entrar';
$cadastroAberto = strtolower((string) env('CADASTRO_ABERTO', 'true')) === 'true';
?>
<div class="public-wrap">
    <div class="brand-hero">
        <div class="logo"><?= icone('bola') ?></div>
        <h1>Futebol</h1>
        <p>Presença, times equilibrados e cobrança do racha.</p>
    </div>

    <?php if ($cadastroAberto) : ?>
    <div class="segmented mb-3" id="segLogin" role="tablist">
        <button type="button" class="ativo" data-valor="entrar" role="tab" aria-selected="true">Entrar</button>
        <button type="button" data-valor="cadastrar" role="tab" aria-selected="false">Criar conta</button>
    </div>
    <?php endif; ?>

    <form id="formEntrar" data-painel="entrar" autocomplete="on">
        <div class="inset-list">
            <div class="field-row"><label for="loginEmail">E-mail</label><input id="loginEmail" name="email" type="email" inputmode="email" autocomplete="username" placeholder="voce@email.com" required></div>
            <div class="field-row"><label for="loginSenha">Senha</label><input id="loginSenha" name="senha" type="password" autocomplete="current-password" placeholder="Obrigatória" required></div>
        </div>
        <div class="input-erro" id="erroEntrar" role="alert"></div>
        <button class="btn-ios bloco mt-3" type="submit">Entrar</button>
    </form>

    <?php if ($cadastroAberto) : ?>
    <form id="formCadastrar" data-painel="cadastrar" class="hidden" autocomplete="on">
        <div class="inset-list">
            <div class="field-row"><label for="cadNome">Nome</label><input id="cadNome" name="nome" type="text" autocomplete="name" placeholder="Seu nome" maxlength="120" required></div>
            <div class="field-row"><label for="cadEmail">E-mail</label><input id="cadEmail" name="email" type="email" inputmode="email" autocomplete="username" placeholder="voce@email.com" maxlength="150" required></div>
            <div class="field-row"><label for="cadSenha">Senha</label><input id="cadSenha" name="senha" type="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres" minlength="8" required></div>
        </div>
        <div class="input-erro" id="erroCadastrar" role="alert"></div>
        <button class="btn-ios bloco mt-3" type="submit">Criar conta de administrador</button>
        <p class="field-note text-center">Você cadastra seus grupos, jogadores e jogos. Quem joga só confirma presença pelo link.</p>
    </form>
    <?php endif; ?>
</div>
