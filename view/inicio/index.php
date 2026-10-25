<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Início';
$primeiroNome = explode(' ', trim($_SESSION['sess_usuario']['nome']))[0];
?>
<h1 class="large-title">Olá, <?= e($primeiroNome) ?></h1>
<p class="subtitle">Seus próximos jogos e grupos.</p>

<div id="inicioConteudo"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
