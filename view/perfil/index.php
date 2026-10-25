<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Meus dados';
?>
<h1 class="large-title">Meus dados</h1>
<p class="subtitle" id="perfilSub">&nbsp;</p>

<form id="formPerfil" autocomplete="on">
    <div class="sec-title">Conta</div>
    <div class="inset-list">
        <div class="field-row"><label for="uNome">Nome</label><input id="uNome" name="nome" type="text" autocomplete="name" maxlength="120" required></div>
        <div class="field-row"><label for="uEmail">E-mail</label><input id="uEmail" name="email" type="email" inputmode="email" autocomplete="username" maxlength="150" required></div>
        <div class="field-row"><label for="uTelefone">WhatsApp</label><input id="uTelefone" name="telefone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="(62) 99999-8888" required></div>
    </div>
    <div class="field-note">O e-mail é o que você usa para entrar. O WhatsApp é o número pelo qual outros admins te colocam nos grupos deles.</div>
    <div class="input-erro" id="erroPerfil" role="alert"></div>
    <button type="submit" class="btn-ios bloco mt-3" id="btnSalvarPerfil">Salvar dados</button>
</form>

<form id="formSenha" autocomplete="off">
    <div class="sec-title">Trocar senha</div>
    <div class="inset-list">
        <div class="field-row"><label for="uSenhaAtual">Senha atual</label><input id="uSenhaAtual" name="senha_atual" type="password" autocomplete="current-password" required></div>
        <div class="field-row"><label for="uSenhaNova">Nova senha</label><input id="uSenhaNova" name="senha_nova" type="password" autocomplete="new-password" minlength="8" placeholder="Mínimo 8 caracteres" required></div>
    </div>
    <div class="input-erro" id="erroSenha" role="alert"></div>
    <button type="submit" class="btn-ios sec bloco mt-3" id="btnSenha">Trocar senha</button>
</form>

<div class="sec-title">Grupos que você administra</div>
<div id="perfilGrupos"><div class="carregando"><span class="spin"></span>Carregando…</div></div>

<a class="btn-ios perigo bloco mt-4" href="?tela=sair"><?= icone('sair') ?> Sair da conta</a>
