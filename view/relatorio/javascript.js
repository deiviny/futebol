/* Relatório do jogo: todas as partidas, desempenho de cada jogador e texto para o WhatsApp */
$(function () {
    var R = null;
    var aba = 'partidas';

    function virgula(n) { return String(n).replace('.', ','); }
    function dot(cor) { return '<span class="team-dot" style="--team:' + esc(cor) + '"></span>'; }
    function minutos(s) { return Math.max(1, Math.round(s / 60)); }
    function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }
    function letra(r) { return r === 'V' ? 'V' : (r === 'E' ? 'E' : 'D'); }

    api('relatorio', 'carregar', { id: JOGO_ID }).done(function (res) {
        R = res.relatorio;
        document.title = 'Relatório · ' + R.jogo.grupo_nome + ' · Futebol';
        $('.nav-back').attr('href', '?tela=jogo&id=' + R.jogo.id);
        render();
    }).fail(function () {
        $('#relConteudo').html('<div class="empty"><h3>Relatório não encontrado</h3><p>O jogo pode ter sido excluído.</p><a class="btn-ios" href="?tela=grupos">Voltar aos grupos</a></div>');
    });

    // ---------- desenho ----------
    function render() {
        var T = R.totais;
        var h = '<h1 class="large-title">Relatório da noite</h1>' +
            '<p class="subtitle">' + esc(R.jogo.grupo_nome) + ' · ' + esc(R.jogo.data_fmt) + (R.jogo.arena_nome ? ' · ' + esc(R.jogo.arena_nome) : '') + '</p>';

        if (!R.partidas.length) {
            $('#relConteudo').html(h + '<div class="empty"><h3>Ainda não há partidas</h3><p>Inicie uma partida na tela do jogo e o relatório aparece aqui.</p>' +
                '<a class="btn-ios" href="?tela=jogo&id=' + R.jogo.id + '">Voltar ao jogo</a></div>');
            return;
        }

        h += '<div class="stat-row">' +
            '<div class="stat"><b>' + T.partidas + '</b><small>Partidas</small></div>' +
            '<div class="stat"><b>' + T.gols + '</b><small>Gols</small></div>' +
            '<div class="stat"><b>' + T.jogadores + '</b><small>Jogadores</small></div>' +
            '<div class="stat"><b>' + minutos(T.segundos) + '</b><small>Min. jogados</small></div></div>';
        if (T.primeira_hora) {
            h += '<div class="sec-foot" style="text-align:center">Começou às ' + esc(T.primeira_hora) + (T.ultima_hora ? ' · última partida terminou às ' + esc(T.ultima_hora) : '') + '</div>';
        }
        if (T.em_andamento) {
            h += '<div class="banner aviso mt-2"><div>Há uma partida em andamento. Ela entra no relatório quando for encerrada.</div></div>';
        }

        h += '<div class="segmented mt-3 rel-abas" id="relAbas">' +
            ['partidas:Partidas', 'jogadores:Jogadores', 'texto:Texto'].map(function (x) {
                var p = x.split(':');
                return '<button type="button" data-aba="' + p[0] + '" class="' + (aba === p[0] ? 'ativo' : '') + '">' + p[1] + '</button>';
            }).join('') + '</div>';

        h += '<section class="rel-sec" data-sec="partidas"' + (aba === 'partidas' ? '' : ' hidden') + '>' + secPartidas() + '</section>' +
            '<section class="rel-sec" data-sec="jogadores"' + (aba === 'jogadores' ? '' : ' hidden') + '>' + secJogadores() + '</section>' +
            '<section class="rel-sec" data-sec="texto"' + (aba === 'texto' ? '' : ' hidden') + '>' + secTexto() + '</section>';

        $('#relConteudo').html(h);
    }

    // ----- todas as partidas -----
    function ladoTopo(L, venceu, saiu, direita) {
        return '<div class="hist-lado' + (direita ? ' dir' : '') + (venceu ? ' venceu' : '') + '" style="--team:' + esc(L.cor) + '">' +
            '<span class="team-dot"></span><div class="nome"><span>' + esc(L.colete_nome) + '</span><small>' + esc(L.nome) + (L.entrou ? ' · entrou' : '') + (saiu ? ' · saiu' : '') + '</small></div></div>';
    }

    function elenco(L) {
        var h = '<div class="elenco-col" style="--team:' + esc(L.cor) + '"><div class="elenco-tit">' + esc(L.colete_nome) + ' · ' + L.jogadores.length + '</div>';
        L.jogadores.forEach(function (j) {
            h += '<div>' + esc(j.nome) + (j.emprestado ? '<span class="emp" title="Estava no ' + esc(j.origem || '') + '">veio do ' + esc(j.origem || 'time que saiu') + '</span>' : '') + '</div>';
        });
        return h + '</div>';
    }

    function desempate(p) {
        var D = p.desempate;
        if (!D) { return ''; }
        if (D.tipo === 'manual') {
            return '<div class="desempate"><div class="des-txt">Empate · o administrador escolheu quem saiu.</div></div>';
        }
        var nome = { a: esc(p.a.colete_nome), b: esc(p.b.colete_nome) };
        var lado = function (l, num) {
            return '<div class="des-lado' + (D.lado_ganhou === l ? ' ganhou' : '') + '" style="--team:' + esc(p[l].cor) + '"><small>' + nome[l] + ' · ' + (D.lado_par === l ? 'PAR' : 'ÍMPAR') + '</small><b>' + num + '</b></div>';
        };
        return '<div class="desempate"><div class="des-titulo">Empate: par ou ímpar</div><div class="des-nums">' + lado('a', D.num_a) + '<span class="des-op">+</span>' + lado('b', D.num_b) +
            '<span class="des-op">=</span><div class="des-soma"><small>soma</small><b>' + D.soma + '</b></div></div>' +
            '<div class="des-txt">Deu <b>' + (D.resultado === 'par' ? 'par' : 'ímpar') + '</b>: o colete <b>' + nome[D.lado_ganhou] + '</b> ganhou e ficou na quadra.</div></div>';
    }

    function cardPartida(p) {
        var vivo = p.situacao !== 'finalizada';
        var h = '<div class="hist-card rel-partida"><div class="hist-top"><b>Partida ' + p.numero + '</b><span>' + esc(p.hora) + (p.hora_fim ? '–' + esc(p.hora_fim) : '') + ' · ' + minutos(p.segundos) + ' min' +
            (vivo ? ' · em andamento' : (p.lado_vencedor ? '' : ' · empate')) + '</span></div>' +
            '<div class="hist-placar">' + ladoTopo(p.a, p.lado_vencedor === 'a', p.lado_saiu === 'a', false) +
            '<div class="hist-nums">' + p.a.gols + ' × ' + p.b.gols + '</div>' +
            ladoTopo(p.b, p.lado_vencedor === 'b', p.lado_saiu === 'b', true) + '</div>';

        h += desempate(p);
        h += '<div class="elencos">' + elenco(p.a) + elenco(p.b) + '</div>';

        if (p.gols.length) {
            h += '<div class="sec-title" style="padding-left:0">Gols</div><div class="inset-list" style="box-shadow:none">';
            p.gols.forEach(function (g) {
                var L = p[g.lado];
                var titulo = g.nome ? esc(g.nome) : '<span style="color:var(--label-2)">Autor não informado</span>';
                h += '<div class="row-item"><div class="min-gol">' + g.minuto + '\'</div>' + dot(L.cor) +
                    '<div class="row-main"><div class="row-title">' + titulo + '</div><div class="row-sub">' + (g.contra ? 'Gol contra · vale para o ' : 'Colete ') + esc(L.colete_nome) + '</div></div></div>';
            });
            h += '</div>';
        } else {
            h += '<div class="hist-sub">Sem gols.</div>';
        }
        return h + '</div>';
    }

    function secPartidas() {
        return R.partidas.map(cardPartida).join('');
    }

    // ----- cada jogador -----
    function selos(seq) {
        return '<span class="selos">' + seq.map(function (r) { return '<i class="selo ' + letra(r) + '">' + letra(r) + '</i>'; }).join('') + '</span>';
    }

    function cardJogador(j, i) {
        var resumo = j.partidas
            ? plural(j.partidas, 'partida', 'partidas') + ' · ' + j.vitorias + 'V ' + j.empates + 'E ' + j.derrotas + 'D · ' + minutos(j.segundos) + ' min'
            : 'Não jogou ainda';
        var h = '<details class="rel-jog"><summary><div class="rank-pos' + (i < 3 && j.gols > 0 ? ' top' : '') + '">' + (i + 1) + '</div>' +
            '<div class="row-main"><div class="row-title">' + esc(j.nome) + '</div><div class="row-sub">' + resumo + '</div>' +
            (j.sequencia.length ? '<div style="margin-top:3px">' + selos(j.sequencia) + '</div>' : '') + '</div>' +
            '<div class="rank-gols">' + j.gols + '<small>gol' + (j.gols === 1 ? '' : 's') + '</small></div></summary>';

        h += '<div class="rel-jog-corpo"><div class="rel-chips">' +
            (j.aproveitamento !== null ? '<span class="chip azul">Aproveitamento ' + j.aproveitamento + '%</span>' : '') +
            (j.esperou ? '<span class="chip">Esperou ' + plural(j.esperou, 'partida', 'partidas') + '</span>' : '') +
            (j.gols_contra ? '<span class="chip vermelho">' + plural(j.gols_contra, 'gol contra', 'gols contra') + '</span>' : '') +
            (j.ordem_chegada ? '<span class="chip">Chegou em ' + j.ordem_chegada + 'º' + (j.chegada_hora ? ' · ' + esc(j.chegada_hora) : '') + '</span>' : '') + '</div>';

        if (j.jogos.length) {
            h += '<div class="inset-list" style="box-shadow:none">';
            j.jogos.forEach(function (g) {
                var gols = g.gols.length ? ' · ⚽ ' + g.gols.map(function (m) { return m + '\''; }).join(' ') : '';
                var contra = g.gols_contra_proprio ? ' · ' + plural(g.gols_contra_proprio, 'gol contra', 'gols contra') : '';
                var veio = g.veio_do_saiu ? ' · veio do ' + esc(g.origem || 'time que saiu') : (g.entrou ? ' · veio da lista' : '');
                h += '<div class="row-item">' + dot(g.cor) +
                    '<div class="row-main"><div class="row-title">Partida ' + g.numero + ' · ' + esc(g.colete_nome) + ' ' + g.gols_pro + ' × ' + g.gols_contra + ' ' + esc(g.contra_colete_nome) + '</div>' +
                    '<div class="row-sub wrap">' + esc(g.time) + veio + gols + contra + '</div></div>' +
                    '<div class="row-end"><i class="selo ' + letra(g.resultado) + '">' + letra(g.resultado) + '</i></div></div>';
            });
            h += '</div>';
        }
        return h + '</div></details>';
    }

    function secJogadores() {
        var h = '<div class="sec-foot" style="padding-top:12px">Ordenado por gols, vitórias e partidas. Toque no jogador para ver partida por partida.</div><div class="inset-list">';
        R.jogadores.forEach(function (j, i) { h += cardJogador(j, i); });
        return h + '</div>';
    }

    // ----- texto para copiar / WhatsApp -----
    function textoRelatorio() {
        var t = ['⚽ *' + R.jogo.grupo_nome + '* — ' + R.jogo.data_fmt];
        if (R.jogo.arena_nome) { t.push('📍 ' + R.jogo.arena_nome); }
        t.push('');
        t.push('*Partidas* (' + R.totais.partidas + ' · ' + R.totais.gols + ' gols)');
        R.partidas.forEach(function (p) {
            if (p.situacao !== 'finalizada') { return; }
            var l = p.numero + ') ' + p.a.colete_nome + ' ' + p.a.gols + ' × ' + p.b.gols + ' ' + p.b.colete_nome;
            var D = p.desempate;
            if (D && D.tipo === 'par_impar') {
                l += ' — par ou ímpar ' + D.num_a + '+' + D.num_b + '=' + D.soma + ' (' + (D.resultado === 'par' ? 'par' : 'ímpar') + '), ' + p[D.lado_ganhou].colete_nome + ' ficou';
            } else if (D) {
                l += ' — empate';
            }
            t.push(l);
        });

        var art = R.jogadores.filter(function (j) { return j.gols > 0; });
        if (art.length) {
            t.push('');
            t.push('*Artilheiros*');
            art.forEach(function (j) { t.push(j.nome + ' — ' + j.gols + (j.gols === 1 ? ' gol' : ' gols')); });
        }

        t.push('');
        t.push('*Por jogador* (partidas · V-E-D · gols)');
        R.jogadores.forEach(function (j) {
            t.push(j.nome + ' — ' + j.partidas + ' · ' + j.vitorias + '-' + j.empates + '-' + j.derrotas + ' · ' + j.gols + (j.gols_contra ? ' (+' + j.gols_contra + ' contra)' : ''));
        });
        return t.join('\n');
    }

    function secTexto() {
        return '<div class="card-ios mt-3"><div class="pix-box rel-texto" id="relTexto" style="max-height:none;user-select:text;font-family:inherit;font-size:14px;white-space:pre-wrap">' + esc(textoRelatorio()) + '</div>' +
            '<div class="btn-row mt-3"><button type="button" class="btn-ios sec" id="btnCopiarRel">Copiar texto</button>' +
            '<a class="btn-ios verde" target="_blank" rel="noopener" href="' + esc(whatsappLink(textoRelatorio())) + '">Enviar no WhatsApp</a></div></div>';
    }

    // ---------- eventos ----------
    $('#relConteudo').on('click', '#relAbas button', function () {
        aba = String($(this).data('aba'));
        $('#relAbas button').removeClass('ativo');
        $(this).addClass('ativo');
        $('.rel-sec').each(function () { $(this).prop('hidden', $(this).data('sec') !== aba); });
    });
    $('#relConteudo').on('click', '#btnCopiarRel', function () { copiar(textoRelatorio(), 'Texto copiado'); });

    // Imprimir: abre todos os jogadores e mostra todas as seções.
    var abertos = [];
    window.addEventListener('beforeprint', function () {
        abertos = $('.rel-jog').map(function () { return this.open; }).get();
        $('.rel-jog').prop('open', true);
    });
    window.addEventListener('afterprint', function () {
        $('.rel-jog').each(function (i) { this.open = !!abertos[i]; });
    });
    $('#btnImprimir').on('click', function () { window.print(); });
});
