<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Confirmar presença';
$token = isset($_GET['t']) ? preg_replace('/[^0-9a-f]/', '', (string) $_GET['t']) : '';
?>
<script>var TOKEN = <?= json_encode($token) ?>;</script>

<div class="public-wrap">
    <div class="brand-hero">
        <div class="logo"><?= icone('bola') ?></div>
        <h1 id="pubTitulo">Confirmar presença</h1>
        <p id="pubSub">&nbsp;</p>
    </div>

    <div id="pubConteudo"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
</div>
