<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Jogo ao vivo';
$token = isset($_GET['t']) ? preg_replace('/[^0-9a-f]/', '', (string) $_GET['t']) : '';
?>
<script>var TOKEN = <?= json_encode($token) ?>;</script>

<div class="public-wrap aovivo-wrap">
    <div class="aovivo-topo">
        <div>
            <h1 id="avTitulo">Jogo ao vivo</h1>
            <p id="avSub">&nbsp;</p>
        </div>
        <div id="avEu"></div>
    </div>

    <div id="avConteudo"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
</div>

<!-- Folha do gol: quem fez e quem deu a assistência -->
<div class="modal fade" id="modalGolAv" tabindex="-1" role="dialog" aria-labelledby="tituloGolAv" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloGolAv">Gol</h2>
                <span class="btn-plain">&nbsp;</span>
            </div>
            <div class="sheet-body">
                <div class="segmented mb-3" id="segGolAv" role="tablist">
                    <button type="button" class="ativo" data-valor="normal">Gol</button>
                    <button type="button" data-valor="contra">Gol contra</button>
                </div>
                <div id="listaGolAv"></div>
            </div>
        </div>
    </div>
</div>
