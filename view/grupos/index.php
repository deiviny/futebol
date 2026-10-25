<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Grupos';
$acoesNav = '<a class="nav-btn forte" href="?tela=grupo">' . icone('mais') . ' Novo</a>';
?>
<h1 class="large-title">Grupos</h1>
<p class="subtitle">Cada grupo tem seus jogadores, jogos e dados do Pix.</p>

<div id="gruposLista"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
