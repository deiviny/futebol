/* Lista de grupos */
$(function () {
    api('grupos', 'listing').done(function (res) {
        if (!res.lista.length) {
            $('#gruposLista').html('<div class="empty"><h3>Nenhum grupo ainda</h3><p>Crie o grupo do seu racha para cadastrar jogadores e marcar jogos.</p><a class="btn-ios" href="?tela=grupo">Novo grupo</a></div>');
            return;
        }
        var h = '<div class="inset-list">';
        res.lista.forEach(function (g) {
            var linha2 = (g.resumo_fixo || 'Sem jogo fixo') + ' · ' + g.jogadores_por_time + ' por time';
            var linha3 = g.proximo_jogo_fmt ? 'Próximo: ' + g.proximo_jogo_fmt : 'Sem jogo marcado';
            h += '<a class="row-item" href="?tela=grupo&id=' + g.id + '">' +
                '<div class="row-icon verde"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="12 7.5 16 10.4 14.5 15 9.5 15 8 10.4"/></svg></div>' +
                '<div class="row-main"><div class="row-title">' + esc(g.nome) + '</div>' +
                '<div class="row-sub">' + esc(linha2) + '</div><div class="row-sub">' + esc(linha3) + '</div></div>' +
                '<div class="row-end"><span>' + g.total_jogadores + '</span>' + ICONES.seta + '</div></a>';
        });
        $('#gruposLista').html(h + '</div>');
    });
});
