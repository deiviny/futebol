<?php
if (!defined('APP')) {
    exit;
}
$idJogo = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$tituloPagina = 'Jogo';
$voltarUrl = '?tela=grupos';
$voltarTexto = 'Grupos';
$acoesNav = '<button type="button" class="nav-btn hidden" id="btnEditarJogo">Editar</button>';
?>
<script>var JOGO_ID = <?= (int) $idJogo ?>;</script>

<div id="jogoConteudo"><div class="carregando"><span class="spin"></span>Carregando…</div></div>

<!-- Folha: gol -->
<div class="modal fade" id="modalGol" tabindex="-1" role="dialog" aria-labelledby="tituloGol" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloGol">Gol</h2>
                <span class="btn-plain">&nbsp;</span>
            </div>
            <div class="sheet-body">
                <div class="segmented mb-3" id="segGol" role="tablist">
                    <button type="button" class="ativo" data-valor="normal">Gol</button>
                    <button type="button" data-valor="contra">Gol contra</button>
                </div>
                <div id="listaGol"></div>
            </div>
        </div>
    </div>
</div>

<!-- Folha: encerrar partida -->
<div class="modal fade" id="modalEncerrar" tabindex="-1" role="dialog" aria-labelledby="tituloEncerrar" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formEncerrar" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Voltar</button>
                <h2 id="tituloEncerrar">Encerrar partida</h2>
                <button type="submit" class="btn-plain" id="btnEncerrarOk">Encerrar</button>
            </div>
            <div class="sheet-body" id="encerrarCorpo"></div>
        </form>
    </div>
</div>

<!-- Folha: mover jogador -->
<div class="modal fade" id="modalMover" tabindex="-1" role="dialog" aria-labelledby="tituloMover" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloMover">Lista de espera</h2>
                <span class="btn-plain">&nbsp;</span>
            </div>
            <div class="sheet-body"><div id="listaMover"></div></div>
        </div>
    </div>
</div>

<!-- Folha: fechar jogo / valor -->
<div class="modal fade" id="modalFinalizar" tabindex="-1" role="dialog" aria-labelledby="tituloFinalizar" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formFinalizar" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloFinalizar">Fechar jogo</h2>
                <button type="submit" class="btn-plain">Gerar Pix</button>
            </div>
            <div class="sheet-body">
                <div class="inset-list">
                    <div class="field-row"><label for="fValor">Quadra</label><input id="fValor" name="valor_total" type="text" inputmode="numeric" data-mascara="moeda" placeholder="R$ 0,00" required></div>
                    <div class="field-row"><label for="fGolQtd">Goleiros</label><input id="fGolQtd" type="number" min="0" max="6" inputmode="numeric" placeholder="0"></div>
                    <div class="field-row" id="linhaFGolValor"><label for="fGolValor">Cada goleiro</label><input id="fGolValor" type="text" inputmode="numeric" data-mascara="moeda" placeholder="R$ 0,00"></div>
                </div>
                <div class="field-note" id="fPreview">&nbsp;</div><div class="field-note" id="fAviso" style="color:var(--orange-text)"></div><div class="field-note">Informe quanto custou a quadra e os goleiros contratados. O total é dividido entre quem jogou.</div>
                <div class="input-erro" id="erroFinalizar" role="alert"></div>
            </div>
        </form>
    </div>
</div>

<!-- Folha: goleiros contratados -->
<div class="modal fade" id="modalGoleiros" tabindex="-1" role="dialog" aria-labelledby="tituloGoleiros" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formGoleiros" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloGoleiros">Goleiros contratados</h2>
                <button type="submit" class="btn-plain">Salvar</button>
            </div>
            <div class="sheet-body">
                <div class="inset-list">
                    <div class="field-row"><label for="gQtd">Quantidade</label><input id="gQtd" name="goleiros_qtd" type="number" min="0" max="6" inputmode="numeric" required></div>
                    <div class="field-row"><label for="gValor">Cada um</label><input id="gValor" name="goleiros_valor" type="text" inputmode="numeric" data-mascara="moeda" placeholder="R$ 0,00"></div>
                    <div class="field-row"><label for="gNomes">Nomes</label><input id="gNomes" name="goleiros_nomes" type="text" maxlength="200" placeholder="Opcional, separados por vírgula"></div>
                </div>
                <div class="field-note">O valor dos goleiros soma com a quadra no fechamento e é dividido entre quem jogou. Os nomes aparecem na lista copiada para o grupo.</div>
                <div class="input-erro" id="erroGoleiros" role="alert"></div>
            </div>
        </form>
    </div>
</div>

<!-- Folha: editar jogo -->
<div class="modal fade" id="modalEditar" tabindex="-1" role="dialog" aria-labelledby="tituloEditar" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formEditar" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloEditar">Editar jogo</h2>
                <button type="submit" class="btn-plain">Salvar</button>
            </div>
            <div class="sheet-body">
                <div class="inset-list">
                    <div class="field-row"><label for="eData">Data</label><input id="eData" name="data" type="date" required></div>
                    <div class="field-row"><label for="eHora">Horário</label><input id="eHora" name="hora" type="time" required></div>
                    <div class="field-row"><label for="eArena">Arena</label><select id="eArena" name="id_arena"></select></div>
                    <div class="field-row"><label for="ePorTime">Por time</label><input id="ePorTime" name="jogadores_por_time" type="number" min="2" max="11" inputmode="numeric" required></div>
                </div>
                <div class="input-erro" id="erroEditar" role="alert"></div>
                <button type="button" class="btn-ios perigo bloco mt-4" id="btnSemJogo">Sem jogo neste dia</button>
                <button type="button" class="btn-ios perigo bloco mt-2 hidden" id="btnExcluirJogo">Excluir jogo</button>
            </div>
        </form>
    </div>
</div>

<!-- Folha: motivo do "sem jogo" -->
<div class="modal fade" id="modalSemJogo" tabindex="-1" role="dialog" aria-labelledby="tituloSemJogo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formSemJogo" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Voltar</button>
                <h2 id="tituloSemJogo">Sem jogo</h2>
                <button type="submit" class="btn-plain">Confirmar</button>
            </div>
            <div class="sheet-body">
                <div class="inset-list">
                    <div class="field-row"><label for="sMotivo">Motivo</label><input id="sMotivo" name="motivo" type="text" maxlength="200" placeholder="Opcional (ex.: feriado, chuva)"></div>
                </div>
                <div class="field-note">Quem abrir o link verá que não haverá jogo. Dá para reativar depois.</div>
            </div>
        </form>
    </div>
</div>
