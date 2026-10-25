/* Funções comuns a todas as telas (mesmo papel do alertTop/ajax do SOLUS). */

var CSRF = $('meta[name=csrf]').attr('content');

/** Ícones usados nos avisos criados via JS. */
var ICONES = {
    ok: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    x: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    seta: '<svg class="ic chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',
    alerta: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    mais3: '<svg class="ic" viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
    cima: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>',
    baixo: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
    pin: '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
};

/** Escapa texto para HTML. */
function esc(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** 1234.5 => "R$ 1.234,50" */
function moeda(valor) {
    return 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "5562999998888" => "(62) 99999-8888" */
function formataTelefone(numero) {
    var n = String(numero || '').replace(/\D/g, '');
    if (n.indexOf('55') === 0 && n.length > 11) { n = n.substring(2); }
    if (n.length === 11) { return '(' + n.substring(0, 2) + ') ' + n.substring(2, 7) + '-' + n.substring(7); }
    if (n.length === 10) { return '(' + n.substring(0, 2) + ') ' + n.substring(2, 6) + '-' + n.substring(6); }
    return numero || '';
}

/** Aviso no topo da tela: tipo = 'success' | 'error'. */
function alertTop(tipo, mensagem) {
    var $a = $('<div class="toast-ios ' + tipo + '" role="status">' + (tipo === 'error' ? ICONES.x : ICONES.ok) + '<span>' + esc(mensagem) + '</span></div>');
    $('#alertas').append($a);
    setTimeout(function () {
        $a.addClass('saindo');
        setTimeout(function () { $a.remove(); }, 320);
    }, tipo === 'error' ? 5000 : 2600);
}

/**
 * Chama um controller pelo router.php.
 * api('grupos', 'listing', {..}).done(function (res) {..})
 * Erros viram aviso no topo; passe { silencioso: true } em opcoes para tratar sozinho.
 */
function api(modulo, acao, dados, opcoes) {
    opcoes = opcoes || {};
    var corpo = $.extend({}, dados || {}, {
        controller: 'view/' + modulo + '/controller.php',
        acao: acao,
        csrf: CSRF
    });

    var req = $.ajax({ url: 'router.php', type: 'POST', dataType: 'json', data: corpo });

    req.fail(function (xhr) {
        var res = xhr.responseJSON || {};
        if (res.login) { window.location.href = '?tela=login'; return; }
        if (res.recarregar) { window.location.reload(); return; }
        if (!opcoes.silencioso) {
            alertTop('error', res.error || 'Não foi possível concluir. Verifique sua conexão e tente de novo.');
        }
    });

    return req;
}

/** Trava o botão enquanto a ação roda. */
function comTrava($botao, promessa) {
    $botao.prop('disabled', true).addClass('disabled');
    return promessa.always(function () { $botao.prop('disabled', false).removeClass('disabled'); });
}

/** Folha de confirmação estilo iOS. confirmar('Excluir?', 'Excluir', true, function () {..}) */
function confirmar(mensagem, textoBotao, perigoso, aoConfirmar) {
    $('#confirmarMensagem').text(mensagem);
    $('#confirmarOk').text(textoBotao).toggleClass('perigo', !!perigoso).off('click').on('click', function () {
        $('#modalConfirmar').one('hidden.bs.modal', aoConfirmar).modal('hide');
    });
    $('#modalConfirmar').modal('show');
}

/** Copia texto (com alternativa para páginas sem HTTPS). */
function copiar(texto, mensagem) {
    function ok() { alertTop('success', mensagem || 'Copiado'); }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(ok, function () { copiarAntigo(texto, ok); });
    } else {
        copiarAntigo(texto, ok);
    }
}
function copiarAntigo(texto, ok) {
    var $t = $('<textarea readonly style="position:fixed;opacity:0;top:0"></textarea>').val(texto).appendTo('body');
    $t[0].select();
    try { document.execCommand('copy'); ok(); } catch (e) { alertTop('error', 'Não foi possível copiar.'); }
    $t.remove();
}

/** Link do WhatsApp. Sem número, abre a lista de conversas para escolher. */
function whatsappLink(texto, numero) {
    return 'https://wa.me/' + (numero ? String(numero).replace(/\D/g, '') : '') + '?text=' + encodeURIComponent(texto);
}

/** Abre uma folha (modal) já limpando erros anteriores. */
function abrirFolha(id) {
    $(id).find('.input-erro').text('');
    $(id).modal('show');
}

/** Lê um parâmetro da URL. */
function param(nome) {
    return new URLSearchParams(window.location.search).get(nome);
}

/** Controle segmentado: mostra o painel [data-seg="valor"] do botão ativo. */
function iniciaSegmentado($seg, aoTrocar) {
    $seg.on('click', 'button', function () {
        var v = $(this).data('valor');
        $seg.find('button').removeClass('ativo').attr('aria-selected', 'false');
        $(this).addClass('ativo').attr('aria-selected', 'true');
        $('[data-painel]').addClass('hidden');
        $('[data-painel="' + v + '"]').removeClass('hidden');
        if (aoTrocar) { aoTrocar(v); }
    });
}

/** Rótulo "chip" de situação do jogo. */
function chipSituacao(situacao) {
    var mapa = {
        agendado: ['azul', 'Agendado'],
        em_andamento: ['laranja', 'Em andamento'],
        finalizado: ['verde', 'Finalizado'],
        cancelado: ['vermelho', 'Sem jogo']
    };
    var m = mapa[situacao] || ['', situacao];
    return '<span class="chip ' + m[0] + '">' + m[1] + '</span>';
}
