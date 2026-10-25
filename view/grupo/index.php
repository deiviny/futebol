<?php
if (!defined('APP')) {
    exit;
}
$idGrupo = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$tituloPagina = $idGrupo ? 'Grupo' : 'Novo grupo';
$voltarUrl = '?tela=grupos';
$voltarTexto = 'Grupos';
?>
<script>var GRUPO_ID = <?= (int) $idGrupo ?>;</script>

<h1 class="large-title" id="grupoTitulo"><?= $idGrupo ? '&nbsp;' : 'Novo grupo' ?></h1>
<p class="subtitle" id="grupoSub"><?= $idGrupo ? '&nbsp;' : 'Defina o grupo, o jogo fixo e os dados do Pix.' ?></p>

<?php if ($idGrupo) : ?>
<div class="segmented mb-3" id="segGrupo" role="tablist">
    <button type="button" class="ativo" data-valor="jogos" role="tab" aria-selected="true">Jogos</button>
    <button type="button" data-valor="jogadores" role="tab" aria-selected="false">Jogadores</button>
    <button type="button" data-valor="ranking" role="tab" aria-selected="false">Ranking</button>
    <button type="button" data-valor="ajustes" role="tab" aria-selected="false">Ajustes</button>
</div>

<!-- Jogos -->
<section data-painel="jogos">
    <button class="btn-ios sec bloco" type="button" id="btnNovoJogo"><?= icone('mais') ?> Novo jogo</button>
    <div id="jogosLista"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
</section>

<!-- Jogadores -->
<section data-painel="jogadores" class="hidden">
    <button class="btn-ios sec bloco" type="button" id="btnNovoJogador"><?= icone('mais') ?> Adicionar jogador</button>
    <div id="jogadoresLista"></div>
</section>
<?php endif; ?>

<!-- Ranking -->
<section data-painel="ranking" class="hidden">
    <div class="segmented mb-2" id="segPeriodo" role="tablist" aria-label="Período">
        <button type="button" class="ativo" data-valor="tudo">Sempre</button>
        <button type="button" data-valor="ano">Este ano</button>
        <button type="button" data-valor="30d">30 dias</button>
    </div>
    <div class="segmented mb-3" id="segOrdem" role="tablist" aria-label="Ordenar por">
        <button type="button" class="ativo" data-valor="gols">Gols</button>
        <button type="button" data-valor="vitorias">Vitórias</button>
        <button type="button" data-valor="aproveitamento">Aproveitamento</button>
        <button type="button" data-valor="partidas">Partidas</button>
    </div>
    <div id="rankingLista"><div class="carregando"><span class="spin"></span>Carregando…</div></div>
</section>

<!-- Ajustes / novo grupo -->
<section data-painel="ajustes" class="<?= $idGrupo ? 'hidden' : '' ?>">
    <form id="formGrupo" autocomplete="off">
        <div class="sec-title">Grupo</div>
        <div class="inset-list">
            <div class="field-row"><label for="gNome">Nome</label><input id="gNome" name="nome" type="text" maxlength="100" placeholder="Ex.: Futebol de sexta" required></div>
            <div class="field-row"><label for="gPorTime">Por time</label><input id="gPorTime" name="jogadores_por_time" type="number" min="2" max="11" value="5" inputmode="numeric" required></div>
        </div>
        <div class="field-note">Quantos jogam em cada time. Os dois primeiros times são formados pelos primeiros a chegar.</div>

        <div class="sec-title">Coletes</div>
        <div class="inset-list">
            <div class="row-pad">
                <div class="colete-linha"><span class="team-dot" id="cDot1"></span><b>Colete 1</b><span class="valor" id="cNome1"></span></div>
                <div class="paleta" id="paleta1" role="radiogroup" aria-label="Cor do colete 1"></div>
            </div>
            <div class="row-pad" style="position:relative">
                <div class="colete-linha"><span class="team-dot" id="cDot2"></span><b>Colete 2</b><span class="valor" id="cNome2"></span></div>
                <div class="paleta" id="paleta2" role="radiogroup" aria-label="Cor do colete 2"></div>
            </div>
        </div>
        <input type="hidden" id="gColete1Nome"><input type="hidden" id="gColete1Cor">
        <input type="hidden" id="gColete2Nome"><input type="hidden" id="gColete2Cor">
        <div class="field-note">As duas cores de colete do grupo. Quem ganha fica com o colete; quem entra veste o colete de quem saiu.</div>

        <div class="sec-title">Jogo fixo semanal</div>
        <div class="inset-list">
            <div class="field-row"><label for="gRecorrente" class="flex-fill">Repetir toda semana</label>
                <label class="ios-switch"><input type="checkbox" id="gRecorrente"><span></span></label></div>
            <div id="blocoFixo" class="hidden">
                <div class="field-row"><label for="gDia">Dia</label>
                    <select id="gDia" name="dia_semana">
                        <option value="0">Domingo</option><option value="1">Segunda</option><option value="2">Terça</option>
                        <option value="3">Quarta</option><option value="4">Quinta</option><option value="5" selected>Sexta</option><option value="6">Sábado</option>
                    </select></div>
                <div class="field-row"><label for="gHorario">Horário</label><input id="gHorario" name="horario" type="time" value="20:00"></div>
                <div class="field-row"><label for="gArena">Arena</label><select id="gArena" name="id_arena_padrao"></select></div>
            </div>
        </div>
        <div class="field-note">Com o jogo fixo ligado, o app cria os jogos das próximas 4 semanas. Em semana sem jogo, use “Sem jogo neste dia”.</div>

        <div class="sec-title">Pix para cobrança</div>
        <div class="inset-list">
            <div class="field-row"><label for="gPixTipo">Tipo da chave</label>
                <select id="gPixTipo" name="pix_tipo">
                    <option value="">Não configurado</option><option value="celular">Celular</option><option value="cpf">CPF</option>
                    <option value="cnpj">CNPJ</option><option value="email">E-mail</option><option value="aleatoria">Chave aleatória</option>
                </select></div>
            <div id="blocoPix" class="hidden">
                <div class="field-row"><label for="gPixChave">Chave</label><input id="gPixChave" name="pix_chave" type="text" maxlength="77" placeholder="Sua chave Pix"></div>
                <div class="field-row"><label for="gPixNome">Recebedor</label><input id="gPixNome" name="pix_nome" type="text" maxlength="25" placeholder="Nome (até 25 letras)"></div>
                <div class="field-row"><label for="gPixCidade">Cidade</label><input id="gPixCidade" name="pix_cidade" type="text" maxlength="15" placeholder="Cidade (até 15 letras)"></div>
            </div>
        </div>
        <div class="field-note">Usado no fim do jogo para gerar o “Pix copia e cola” com o valor de cada um.</div>

        <div class="input-erro" id="erroGrupo" role="alert"></div>
        <button type="submit" class="btn-ios bloco mt-3" id="btnSalvarGrupo"><?= $idGrupo ? 'Salvar alterações' : 'Criar grupo' ?></button>
        <?php if ($idGrupo) : ?>
        <button type="button" class="btn-ios perigo bloco mt-3" id="btnExcluirGrupo">Excluir grupo</button>
        <?php endif; ?>
    </form>
</section>

<?php if ($idGrupo) : ?>
<!-- Folha: novo jogo -->
<div class="modal fade" id="modalJogo" tabindex="-1" role="dialog" aria-labelledby="tituloJogo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formJogo" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloJogo">Novo jogo</h2>
                <button type="submit" class="btn-plain">Criar</button>
            </div>
            <div class="sheet-body">
                <div class="inset-list">
                    <div class="field-row"><label for="jData">Data</label><input id="jData" name="data" type="date" required></div>
                    <div class="field-row"><label for="jHora">Horário</label><input id="jHora" name="hora" type="time" required></div>
                    <div class="field-row"><label for="jArena">Arena</label><select id="jArena" name="id_arena"></select></div>
                    <div class="field-row"><label for="jPorTime">Por time</label><input id="jPorTime" name="jogadores_por_time" type="number" min="2" max="11" inputmode="numeric" required></div>
                </div>
                <div class="input-erro" id="erroJogo" role="alert"></div>
            </div>
        </form>
    </div>
</div>

<!-- Folha: jogador -->
<div class="modal fade" id="modalJogador" tabindex="-1" role="dialog" aria-labelledby="tituloJogador" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formJogador" autocomplete="off">
            <div class="sheet-header">
                <button type="button" class="btn-plain" data-dismiss="modal">Cancelar</button>
                <h2 id="tituloJogador">Novo jogador</h2>
                <button type="submit" class="btn-plain">Salvar</button>
            </div>
            <div class="sheet-body">
                <input type="hidden" name="id" id="pId">
                <div class="inset-list">
                    <div class="field-row"><label for="pNome">Nome</label><input id="pNome" name="nome" type="text" maxlength="120" placeholder="Nome do jogador" required></div>
                    <div class="field-row"><label for="pWhats">WhatsApp</label><input id="pWhats" name="whatsapp" type="tel" inputmode="tel" maxlength="20" placeholder="(62) 99999-8888" required></div>
                    <div class="field-row"><label for="pInsta">Instagram</label><input id="pInsta" name="instagram" type="text" maxlength="61" placeholder="@usuario" autocapitalize="none"></div>
                    <div class="field-row"><label for="pNasc">Nascimento</label><input id="pNasc" name="data_nascimento" type="date"></div>
                    <div class="field-row"><label for="pConvidou">Convidado por</label><select id="pConvidou" name="id_convidado_por"></select></div>
                </div>

                <div class="sec-title">Nível (só você vê)</div>
                <div class="inset-list">
                    <div class="field-row"><label for="pScore" style="flex-basis:60px">Score</label>
                        <input id="pScore" name="score" type="range" min="0" max="10" step="0.5" value="5"><span class="range-valor" id="pScoreValor">5,0</span></div>
                </div>
                <div class="field-note">O score equilibra os times no sorteio. Pode mudar quando quiser.</div>

                <div class="inset-list mt-3" id="blocoAtivo">
                    <div class="field-row"><label for="pAtivo" class="flex-fill">Ativo no grupo</label>
                        <label class="ios-switch"><input type="checkbox" id="pAtivo" checked><span></span></label></div>
                </div>

                <div class="input-erro" id="erroJogador" role="alert"></div>
                <button type="button" class="btn-ios perigo bloco mt-4 hidden" id="btnRemoverJogador">Remover do grupo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
