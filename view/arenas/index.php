<?php
if (!defined('APP')) {
    exit;
}
$tituloPagina = 'Arenas';
$acoesNav = '<button class="nav-btn forte" type="button" id="btnNovaArena">' . icone('mais') . ' Nova</button>';
?>
<h1 class="large-title">Arenas</h1>
<p class="subtitle">Cadastro compartilhado: qualquer administrador usa as arenas, mas só quem cadastrou edita.</p>

<div id="arenasLista"><div class="carregando"><span class="spin"></span>Carregando…</div></div>

<!-- Folha: arena -->
<div class="modal fade" id="modalArena" tabindex="-1" role="dialog" aria-labelledby="tituloArena" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formArena" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloArena">Nova arena</h2>
                <button type="submit" class="btn-plain">Salvar</button>
            </div>
            <div class="sheet-body">
                <input type="hidden" name="id" id="arenaId">
                <div class="inset-list">
                    <div class="field-row"><label for="arenaNome">Nome</label><input id="arenaNome" name="nome" type="text" maxlength="120" placeholder="Ex.: Arena Society" required></div>
                    <div class="field-row"><label for="arenaValor">Valor</label><input id="arenaValor" name="valor" type="text" inputmode="numeric" data-mascara="moeda" placeholder="0,00"></div>
                    <div class="field-row"><label for="arenaEndereco">Endereço</label><input id="arenaEndereco" name="endereco" type="text" maxlength="255" placeholder="Rua, número, bairro" required></div>
                    <div class="field-row"><label for="arenaMapa">Mapa</label><input id="arenaMapa" name="url_mapa" type="url" maxlength="500" placeholder="Link do Google Maps"></div>
                </div>
                <div class="field-note">Valor de referência do aluguel: ele já vem preenchido ao fechar o jogo. No Google Maps, use Compartilhar › Copiar link.</div>
                <div class="input-erro" id="erroArena" role="alert"></div>
                <button type="button" class="btn-ios perigo bloco mt-4 hidden" id="btnExcluirArena">Excluir arena</button>
            </div>
        </form>
    </div>
</div>
