/* Tela inicial: próximos jogos e grupos */
$(function () {
    api('inicio', 'carregar').done(function (res) {
        var h = '';

        if (!res.grupos.length) {
            h = '<div class="empty"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' +
                '<h3>Crie seu primeiro grupo</h3><p>Cadastre o grupo, os jogadores e o horário fixo. O app cria os jogos da semana sozinho.</p>' +
                '<a class="btn-ios" href="?tela=grupo">Novo grupo</a></div>';
            $('#inicioConteudo').html(h);
            return;
        }

        h += '<div class="sec-title">Próximos jogos</div>';
        if (res.jogos.length) {
            h += '<div class="inset-list">';
            res.jogos.forEach(function (j) {
                var sub = j.data_fmt + (j.arena_nome ? ' · ' + j.arena_nome : '');
                var fim = j.situacao === 'cancelado'
                    ? chipSituacao('cancelado')
                    : (j.situacao === 'em_andamento' ? chipSituacao('em_andamento') : '<span>' + j.confirmados + ' confirmado' + (j.confirmados === 1 ? '' : 's') + '</span>');
                h += '<a class="row-item" href="?tela=jogo&id=' + j.id + '">' +
                    '<div class="row-main"><div class="row-title">' + esc(j.grupo_nome) + '</div><div class="row-sub">' + esc(sub) + '</div></div>' +
                    '<div class="row-end">' + fim + ICONES.seta + '</div></a>';
            });
            h += '</div>';
        } else {
            h += '<div class="inset-list"><div class="row-empty">Nenhum jogo nos próximos 15 dias.</div></div>';
        }

        h += '<div class="sec-title">Grupos<a class="acao" href="?tela=grupo">Novo</a></div><div class="inset-list">';
        res.grupos.forEach(function (g) {
            var sub = g.resumo_fixo || 'Sem jogo fixo';
            h += '<a class="row-item" href="?tela=grupo&id=' + g.id + '">' +
                '<div class="row-main"><div class="row-title">' + esc(g.nome) + '</div><div class="row-sub">' + esc(sub) + ' · ' + g.total_jogadores + ' jogadores</div></div>' +
                '<div class="row-end">' + ICONES.seta + '</div></a>';
        });
        h += '</div>';

        $('#inicioConteudo').html(h);
    });
});
