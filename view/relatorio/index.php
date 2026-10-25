<?php
if (!defined('APP')) {
    exit;
}
$idJogo = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$tituloPagina = 'Relatório do jogo';
$voltarUrl = '?tela=jogo&id=' . $idJogo;
$voltarTexto = 'Jogo';
$acoesNav = '<button type="button" class="nav-btn" id="btnImprimir">Imprimir</button>';
?>
<script>var JOGO_ID = <?= (int) $idJogo ?>;</script>

<div id="relConteudo"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
