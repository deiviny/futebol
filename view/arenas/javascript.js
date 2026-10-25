/* Cadastro de arenas */
$(function () {
    var arenas = [];

    function carregar() {
        api('arenas', 'listing').done(function (res) {
            arenas = res.lista;
            if (!arenas.length) {
                $('#arenasLista').html('<div class="empty"><h3>Nenhuma arena cadastrada</h3><p>Cadastre o local onde o racha acontece, com valor e link do mapa.</p><button class="btn-ios" id="btnNovaArena2">Nova arena</button></div>');
                return;
            }
            var h = '<div class="inset-list">';
            arenas.forEach(function (a) {
                h += '<div class="row-item' + (a.minha ? ' tappable' : '') + '" data-id="' + a.id + '">' +
                    '<div class="row-icon laranja">' + ICONES.pin + '</div>' +
                    '<div class="row-main"><div class="row-title">' + esc(a.nome) + '</div>' +
                    '<div class="row-sub wrap">' + esc(a.endereco) + '</div>' +
                    '<div class="row-sub">' + (a.valor > 0 ? moeda(a.valor) : 'Valor não informado') + (a.minha ? '' : ' · por ' + esc(a.cadastrada_por || 'outro admin')) + '</div></div>' +
                    '<div class="row-end">' +
                    (a.url_mapa ? '<a class="btn-ios sec peq" href="' + esc(a.url_mapa) + '" target="_blank" rel="noopener" data-mapa>Mapa</a>' : '') +
                    (a.minha ? ICONES.seta : '') + '</div></div>';
            });
            $('#arenasLista').html(h + '</div><div class="sec-foot">Toque em uma arena sua para editar.</div>');
        });
    }

    function abrir(a) {
        $('#tituloArena').text(a ? 'Editar arena' : 'Nova arena');
        $('#arenaId').val(a ? a.id : '');
        $('#arenaNome').val(a ? a.nome : '');
        $('#arenaValor').val(a && a.valor > 0 ? String(a.valor).replace('.', ',') : '');
        $('#arenaEndereco').val(a ? a.endereco : '');
        $('#arenaMapa').val(a ? (a.url_mapa || '') : '');
        $('#btnExcluirArena').toggleClass('hidden', !a);
        abrirFolha('#modalArena');
    }

    $(document).on('click', '#btnNovaArena, #btnNovaArena2', function () { abrir(null); });
    $('#arenasLista').on('click', '.row-item.tappable', function (e) {
        if ($(e.target).closest('[data-mapa]').length) { return; }
        var id = $(this).data('id');
        abrir(arenas.filter(function (a) { return a.id === id; })[0]);
    });

    $('#modalArena').on('shown.bs.modal', function () { if (!$('#arenaId').val()) { $('#arenaNome').trigger('focus'); } });

    $('#formArena').on('submit', function (e) {
        e.preventDefault();
        $('#erroArena').text('');
        comTrava($('#formArena button[type=submit]'), api('arenas', 'salvar', Object.fromEntries(new FormData(this)), { silencioso: true }))
            .done(function (res) {
                $('#modalArena').modal('hide');
                alertTop('success', res.mensagem);
                carregar();
            })
            .fail(function (xhr) { $('#erroArena').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    $('#btnExcluirArena').on('click', function () {
        var id = $('#arenaId').val();
        $('#modalArena').one('hidden.bs.modal', function () {
            confirmar('Excluir esta arena? Jogos antigos continuam mostrando o nome dela.', 'Excluir arena', true, function () {
                api('arenas', 'excluir', { id: id }).done(function (res) { alertTop('success', res.mensagem); carregar(); });
            });
        }).modal('hide');
    });

    carregar();
});
