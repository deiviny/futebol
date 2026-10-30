/* Tela do jogo: confirmações, chegada, sorteio, partidas (coletes, lista de espera, gols, par ou ímpar), fechamento e Pix */
$(function () {
    var J = null;        // jogo carregado
    var ARENAS = [];
    var timerId = null;  // cronômetro da partida
    var T0 = 0;          // instante (ms) em que a partida começou, no relógio do navegador
    var golLado = 'a';   // lado que vai ganhar o gol aberto na folha
    var moverJogador = 0;
    var modoDesempate = 'par_impar'; // como decidir o empate na folha de encerrar

    // ---------- chamadas ----------
    function chama(acao, dados, aoConcluir) {
        var corpo = $.extend({ id: JOGO_ID }, dados || {});
        return api('jogo', acao, corpo).done(function (res) {
            if (res.jogo) { J = res.jogo; render(); }
            if (aoConcluir) { aoConcluir(res); }
        });
    }
    function aviso(r) { if (r && r.mensagem) { alertTop('success', r.mensagem); } }

    api('jogo', 'carregar', { id: JOGO_ID }).done(function (res) {
        ARENAS = res.arenas;
        J = res.jogo;
        render();
    }).fail(function () {
        $('#jogoConteudo').html('<div class="empty"><h3>Jogo não encontrado</h3><p>Ele pode ter sido excluído.</p><a class="btn-ios" href="?tela=grupos">Voltar aos grupos</a></div>');
    });

    // ---------- utilidades de desenho ----------
    function nomeTime(n) { return n === 1 ? '1 jogador' : n + ' jogadores'; }
    function virgula(n) { return String(n).replace('.', ','); }
    function fmtTempo(s) { var m = Math.floor(s / 60), r = s % 60; return ('0' + m).slice(-2) + ':' + ('0' + r).slice(-2); }
    function duracao(s) { return Math.max(1, Math.round(s / 60)) + ' min'; }
    function dot(cor) { return '<span class="team-dot" style="--team:' + esc(cor) + '"></span>'; }

    function jogadoresEmTimes() {
        var n = J.fila.length;
        J.times.forEach(function (t) { n += t.jogadores.length; });
        return n;
    }

    function textoConvite() {
        return '⚽ ' + J.grupo_nome + ' — ' + J.data_fmt +
            (J.arena ? '\n📍 ' + J.arena.nome : '') +
            '\n\nConfirme sua presença (leva 5 segundos):\n' + J.link_publico;
    }

    function iniciaTimer() {
        if (timerId) { clearInterval(timerId); timerId = null; }
        if (!J || !J.partida_atual) { return; }
        T0 = Date.now() - J.partida_atual.segundos * 1000;
        var tick = function () { $('#timerPartida').text(fmtTempo(Math.max(0, Math.floor((Date.now() - T0) / 1000)))); };
        tick();
        timerId = setInterval(tick, 1000);
    }

    // ---------- desenho ----------
    function render() {
        document.title = J.grupo_nome + ' · ' + J.data_fmt + ' · Futebol';
        $('.nav-back').attr('href', '?tela=grupo&id=' + J.id_grupo).find('span').text(J.grupo_nome);
        $('#btnEditarJogo').toggleClass('hidden', !J.aberto || J.situacao !== 'agendado');

        var h = '<h1 class="large-title">' + esc(J.data_fmt) + '</h1>' +
            '<p class="subtitle">' + chipSituacao(J.situacao) + ' &nbsp;' + esc(J.grupo_nome) + '</p>';

        if (J.arena) {
            h += '<div class="inset-list"><div class="row-item">' +
                '<div class="row-icon laranja">' + ICONES.pin + '</div>' +
                '<div class="row-main"><div class="row-title">' + esc(J.arena.nome) + '</div><div class="row-sub wrap">' + esc(J.arena.endereco) + '</div></div>' +
                '<div class="row-end">' + (J.arena.url_mapa ? '<a class="btn-ios sec peq" href="' + esc(J.arena.url_mapa) + '" target="_blank" rel="noopener">Mapa</a>' : '') + '</div></div></div>';
        }

        if (J.situacao === 'cancelado') {
            h += blocoCancelado();
        } else if (J.situacao === 'finalizado') {
            h += blocoFinalizado();
        } else {
            h += blocoAberto();
        }

        $('#jogoConteudo').html(h);
        iniciaTimer();
    }

    function blocoCancelado() {
        return '<div class="banner erro"><div><b>Sem jogo neste dia.</b>' + (J.motivo_cancelamento ? '<br>' + esc(J.motivo_cancelamento) : '') +
            '<br>Quem abrir o link de confirmação verá este aviso.</div></div>' +
            '<button class="btn-ios bloco mt-3" data-act="reativar">Reativar jogo</button>' +
            '<button class="btn-ios perigo bloco mt-2" data-act="excluir-cancelado">Excluir este dia</button>';
    }

    // ----- jogo aberto: confirmar, chegar, sortear, jogar -----
    function hojeLocal() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    // Depois do sorteio o que importa é a partida e os times; antes dele, no dia do jogo
    // vem a chegada e nos dias anteriores o link de confirmação.
    function blocoAberto() {
        if (J.sorteado) {
            return partPartida() + partTimes() + partHistorico() + partListas() + partLink();
        }
        var noDia = J.data <= hojeLocal();
        return noDia
            ? partSorteio() + partListas() + partLink()
            : partLink() + partListas() + partSorteio();
    }

    function partSorteio() {
        var h = '<div class="sec-head-lg">Sorteio</div>' +
            '<div class="card-ios"><h3>Sorteio dos times</h3><p>Marque “Chegou” para cada jogador na ordem em que chegam. ' +
            'Os primeiros ' + (J.jogadores_por_time * 2) + ' formam os dois primeiros times, equilibrados pelo score (o sorteio por score vale só para essa primeira formação). ' +
            'Cada time veste um dos coletes do grupo (' + esc(J.coletes[0].nome) + ' e ' + esc(J.coletes[1].nome) + '). ' +
            'Quem chegou depois entra na lista de espera, na ordem de chegada, e a lista manda nas próximas partidas.</p></div>' +
            '<button class="btn-ios bloco mt-2" data-act="sortear"' + (J.chegaram < 2 ? ' disabled' : '') + '>Sortear times (' + J.chegaram + ' chegaram)</button>';
        if (J.chegaram >= 2) {
            h += '<button class="btn-ios cinza bloco mt-2" data-act="fechar">Fechar jogo sem sorteio</button>';
        }
        return h;
    }

    // ----- partida: ao vivo ou próxima -----
    function partPartida() {
        if (J.partida_atual) { return blocoAoVivo(J.partida_atual); }
        if (J.proxima) { return blocoProxima(J.proxima); }
        return '';
    }

    function ladoPlacar(P, l, comBotao) {
        var L = P[l];
        return '<div class="placar-lado" style="--team:' + esc(L.cor) + '">' +
            '<div class="placar-colete"><span class="team-dot"></span><span>' + esc(L.colete_nome) + '</span></div>' +
            '<div class="placar-time">' + esc(L.nome) + '</div>' +
            '<div class="placar-gols">' + L.gols + '</div>' +
            (comBotao ? '<button type="button" class="btn-ios peq sec" data-act="gol" data-lado="' + l + '">+ Gol</button>' : '') + '</div>';
    }

    function ladoPreview(L) {
        return '<div class="placar-lado" style="--team:' + esc(L.cor) + '">' +
            '<div class="placar-colete"><span class="team-dot"></span><span>' + esc(L.colete_nome) + '</span></div>' +
            '<div class="placar-time">' + esc(L.nome) + ' · soma ' + virgula(L.soma) + '</div></div>';
    }

    function elencoCol(L) {
        var h = '<div class="elenco-col" style="--team:' + esc(L.cor) + '">';
        L.jogadores.forEach(function (p) {
            h += '<div>' + esc(p.nome) + (p.emprestado ? '<span class="emp" title="Veio do ' + esc(p.origem || '') + '">emprestado</span>' : '') + '</div>';
        });
        return h + '</div>';
    }

    function blocoAoVivo(P) {
        var h = '<div class="sec-head-lg"><span>Partida ' + P.numero + '</span><span class="chip laranja">Ao vivo</span></div>' +
            '<div class="placar-card"><div class="placar">' + ladoPlacar(P, 'a', true) +
            '<div class="placar-meio"><div class="placar-tempo" id="timerPartida">00:00</div><div class="placar-x">×</div></div>' +
            ladoPlacar(P, 'b', true) + '</div>' +
            '<div class="elencos">' + elencoCol(P.a) + elencoCol(P.b) + '</div></div>';

        h += '<div class="sec-title">Gols</div><div class="inset-list">';
        if (P.gols.length) {
            P.gols.forEach(function (g) {
                var cor = P[g.lado].cor;
                var titulo = g.nome ? esc(g.nome) : '<span style="color:var(--label-2)">Autor não informado</span>';
                var sub = g.contra ? 'Gol contra · vale para o colete ' + esc(P[g.lado].colete_nome) : 'Colete ' + esc(P[g.lado].colete_nome);
                h += '<div class="row-item"><div class="min-gol">' + g.minuto + '\'</div>' + dot(cor) +
                    '<div class="row-main"><div class="row-title">' + titulo + '</div><div class="row-sub">' + sub + '</div></div>' +
                    '<div class="row-end"><button type="button" class="btn-plain perigo" data-act="gol-desfazer" data-gol="' + g.id + '" aria-label="Desfazer este gol">' + ICONES.x + '</button></div></div>';
            });
        } else {
            h += '<div class="row-empty">Nenhum gol ainda. Toque em “+ Gol” no colete que marcou.</div>';
        }
        h += '</div>';

        h += '<button type="button" class="btn-ios verde bloco mt-3" data-act="encerrar">Encerrar partida</button>' +
            '<button type="button" class="btn-ios perigo bloco mt-2" data-act="descartar">Descartar partida</button>' +
            '<div class="sec-foot">Descartar apaga a partida (iniciada por engano) e devolve os times como estavam.</div>';
        return h;
    }

    function btnMover(p, mexe) {
        return mexe ? '<button type="button" class="btn-plain" data-act="mover" data-id="' + p.id_jogador + '" data-nome="' + esc(p.nome) + '" aria-label="Opções de ' + esc(p.nome) + '">' + ICONES.mais3 + '</button>' : '';
    }

    function cardLado(t, papel, mexe) {
        var h = '<div><div class="tag-papel" style="padding:0 4px 6px">' + esc(papel) + '</div>' +
            '<div class="team-card" style="--team:' + esc(t.cor) + '"><div class="team-head"><span class="team-dot"></span><b>' + esc(t.nome) + '</b>' +
            (t.colete_nome ? '<span class="chip">Colete ' + esc(t.colete_nome) + '</span>' : '') +
            '<span class="chip">Soma ' + virgula(t.soma) + '</span></div>';
        t.jogadores.forEach(function (p) {
            var tag = p.veio_do_saiu ? '<span class="emp" title="Estava no ' + esc(p.origem || '') + '">veio do ' + esc(p.origem || 'time que saiu') + '</span>'
                : (p.veio_do_saiu === false ? '<span class="emp cinza">de fora</span>' : '');
            h += '<div class="row-item"><div class="row-main"><div class="row-title">' + esc(p.nome) + tag + '</div></div>' +
                '<div class="row-end"><span class="score">' + virgula(p.score.toFixed(1)) + '</span>' + btnMover(p, mexe) + '</div></div>';
        });
        return h + '</div></div>';
    }

    // Como o empate foi decidido, em linhas curtas (histórico) ou no cartão grande (resultado da última).
    function nomeLado(P, l) { return esc(P['colete_nome_' + l] || P[l].colete_nome); }

    function textoDesempateCurto(D, nomes) {
        if (!D) { return ''; }
        if (D.tipo === 'manual') { return 'Empate · quem saiu foi escolhido pelo administrador'; }
        return 'Par ou ímpar: ' + nomes.a + ' (' + (D.lado_par === 'a' ? 'par' : 'ímpar') + ') ' + D.num_a + ' + ' + nomes.b + ' (' + (D.lado_par === 'b' ? 'par' : 'ímpar') + ') ' + D.num_b +
            ' = ' + D.soma + ', deu ' + (D.resultado === 'par' ? 'par' : 'ímpar') + ' · ' + nomes[D.lado_ganhou] + ' ficou';
    }

    function cartaoDesempate(U) {
        var D = U.desempate;
        if (!D) { return ''; }
        if (D.tipo === 'manual') {
            return '<div class="desempate"><div class="des-txt">Empate: o administrador escolheu quem saiu (<b>' + esc(U.saiu) + '</b>).</div></div>';
        }
        var nome = { a: esc(U.colete_nome_a), b: esc(U.colete_nome_b) };
        var cor = { a: U.cor_a, b: U.cor_b };
        var lado = function (l, num) {
            return '<div class="des-lado' + (D.lado_ganhou === l ? ' ganhou' : '') + '" style="--team:' + esc(cor[l]) + '"><small>' + nome[l] + ' · ' + (D.lado_par === l ? 'PAR' : 'ÍMPAR') + '</small><b>' + num + '</b></div>';
        };
        return '<div class="desempate"><div class="des-titulo">Par ou ímpar</div><div class="des-nums">' + lado('a', D.num_a) + '<span class="des-op">+</span>' + lado('b', D.num_b) +
            '<span class="des-op">=</span><div class="des-soma"><small>soma</small><b>' + D.soma + '</b></div></div>' +
            '<div class="des-txt">Deu <b>' + (D.resultado === 'par' ? 'par' : 'ímpar') + '</b>: o colete <b>' + nome[D.lado_ganhou] + '</b> ganhou e fica na quadra.</div></div>';
    }

    function blocoProxima(X) {
        var h = '', U = X.ultima;
        var mexe = J.aberto;

        if (X.primeira) {
            h += '<div class="sec-head-lg">Primeira partida</div>' +
                '<div class="placar-card"><div class="placar">' + ladoPreview(X.lado_a) + '<div class="placar-meio"><div class="placar-x">×</div></div>' + ladoPreview(X.lado_b) + '</div>' +
                '<div class="elencos">' + elencoCol(X.lado_a) + elencoCol(X.lado_b) + '</div></div>' +
                '<button type="button" class="btn-ios verde bloco" data-act="iniciar">Iniciar partida</button>' +
                '<div class="sec-foot">Quem ganha fica com o colete. Quem perde sai e vai para o fim da lista de espera; o próximo time sai dos primeiros da lista e entra com o colete de quem saiu.</div>';
            return h;
        }

        h += '<div class="sec-head-lg">Próxima partida</div>' +
            '<div class="card-ios resultado-ultima"><div>Partida ' + U.numero + ': ' + esc(U.nome_a) + ' <b>' + U.gols_a + ' × ' + U.gols_b + '</b> ' + esc(U.nome_b) + '</div>' +
            '<span class="chip ' + (U.empate ? 'laranja' : 'verde') + '">' + esc(U.saiu) + ' saiu</span></div>';
        if (U.desempate) { h += cartaoDesempate(U); }
        if (J.partida_reabrivel) {
            h += '<div style="padding:0 4px 8px"><button type="button" class="btn-plain" style="font-size:15px" data-act="reabrir" data-partida="' + J.partida_reabrivel + '">Encerrei sem querer: reabrir a partida ' + U.numero + '</button></div>';
        }

        if (X.erro) {
            return h + '<div class="banner aviso">' + ICONES.alerta + '<div>' + esc(X.erro) + '</div></div>';
        }

        h += '<div class="versus">' + cardLado(X.fica, 'Fica na quadra', mexe) +
            cardLado(X.entra, X.revanche ? 'Revanche: entra de novo' : 'Entra com o colete de quem saiu', mexe) + '</div>';

        var partes = [];
        if (X.de_fora) { partes.push('<b>' + X.de_fora + '</b> de fora, na ordem da lista'); }
        if (X.do_saiu) { partes.push('<b>' + X.do_saiu + '</b> do ' + esc(X.saiu.nome) + ', que acabou de sair'); }
        h += '<div class="sec-foot" style="padding-top:0">Entram os primeiros da lista: ' + partes.join(' e ') + '.</div>';
        if (X.faltam > 0) {
            h += '<div class="banner aviso mt-2">' + ICONES.alerta + '<div>O ' + esc(X.entra.nome) + ' tem ' + nomeTime(X.entra.jogadores.length) + ' (o normal são ' + X.por_time + ') e não há mais ninguém na lista.</div></div>';
        }

        h += '<button type="button" class="btn-ios verde bloco mt-3" data-act="iniciar">Iniciar partida</button>';
        return h;
    }

    // ----- times e lista de espera -----
    function cardTime(t) {
        var mexe = J.aberto && !J.partida_atual;

        var h = '<div class="team-card" style="--team:' + esc(t.cor) + '"><div class="team-head"><span class="team-dot"></span><b>' + esc(t.nome) + '</b>' +
            '<span class="chip">Colete ' + esc(t.colete_nome) + '</span><span class="chip">Soma ' + virgula(t.soma) + '</span></div>';
        t.jogadores.forEach(function (p) {
            h += '<div class="row-item"><div class="row-main"><div class="row-title">' + esc(p.nome) + '</div></div>' +
                '<div class="row-end"><span class="score">' + virgula(p.score.toFixed(1)) + '</span>' + btnMover(p, mexe) + '</div></div>';
        });
        return h + '</div>';
    }

    function linhaFila(p, mexe) {
        return '<div class="row-item' + (p.posicao > 1 && (p.posicao - 1) % J.jogadores_por_time === 0 ? ' bloco-ini' : '') + '">' +
            '<div class="num-chegada fila-pos">' + p.posicao + '</div>' +
            '<div class="row-main"><div class="row-title">' + esc(p.nome) + '</div>' +
            (p.saiu_partida ? '<div class="row-sub">Saiu na partida ' + p.saiu_partida + '</div>' : '') + '</div>' +
            '<div class="row-end"><span class="score">' + virgula(p.score.toFixed(1)) + '</span>' + btnMover(p, mexe) + '</div></div>';
    }

    function partTimes() {
        var aoVivo = !!J.partida_atual;
        var antes = !J.tem_partidas && !aoVivo;
        var mexe = J.aberto;
        var h = '<div class="sec-head-lg">' + (antes ? 'Times e lista de espera' : 'Lista de espera') + '</div>';

        if (J.sem_time.length) {
            h += '<div class="sec-title">Chegaram depois do sorteio</div><div class="inset-list">';
            J.sem_time.forEach(function (p) {
                h += '<div class="row-item"><div class="row-main"><div class="row-title">' + esc(p.nome) + '</div></div>' +
                    '<div class="row-end"><span class="score">' + virgula(p.score.toFixed(1)) + '</span>' +
                    '<button type="button" class="btn-ios peq sec" data-act="time-add" data-id="' + p.id_jogador + '">Entrar na lista</button></div></div>';
            });
            h += '</div><div class="sec-foot">Entram no fim da lista de espera. Quem não entrar na lista nem em um time não é cobrado.</div>';
        }

        if (antes) {
            J.times.forEach(function (t) { h += cardTime(t); });
        }

        // Entre uma partida e outra os primeiros da lista já aparecem no cartão "Entra"; aqui fica o resto.
        var X = J.proxima;
        var pulo = (X && !X.primeira && !X.erro) ? X.entra.jogadores.length : 0;
        var lista = J.fila.slice(pulo);

        if (J.fila.length || antes) {
            h += '<div class="sec-title">' + (pulo ? 'Depois deles na lista' : 'Lista de espera') + '</div>';
        }
        if (lista.length) {
            h += '<div class="inset-list">';
            lista.forEach(function (p) { h += linhaFila(p, mexe); });
            h += '</div>';
        } else if (antes || !pulo) {
            h += '<div class="inset-list"><div class="row-empty">' + (aoVivo || pulo === 0
                ? 'Ninguém esperando. Quem perder volta para a quadra (revanche).'
                : '') + '</div></div>';
        }
        if (J.fila.length) {
            h += '<div class="sec-foot">Quem perde vai para o <b>fim</b> da lista. O próximo time sai dos <b>primeiros</b> da lista, do primeiro para o último; ' +
                'se faltar gente, completa com quem acabou de sair. Toque em ⋯ para mudar alguém de lugar.</div>';
        }

        if (J.diferenca !== null) {
            h += '<div class="sec-foot">Diferença de score entre os dois times: <b>' + virgula(J.diferenca) + '</b> (o sorteio por score vale só para a primeira formação).</div>';
        }

        h += '<div class="btn-row mt-3">' +
            (!J.tem_partidas ? '<button type="button" class="btn-ios cinza" data-act="sortear">Sortear novamente</button>' : '') +
            '<button type="button" class="btn-ios" data-act="fechar">Fechar jogo e cobrar</button></div>';
        if (J.tem_partidas) {
            h += '<div class="sec-foot">Para sortear de novo, descarte as partidas.</div>';
        }
        return h;
    }

    // ----- histórico da noite -----
    function golTag(g, p) {
        var cor = p[g.lado].cor;
        var nome = g.nome ? esc(g.nome) : 'Gol sem autor';
        return '<span class="gol-tag" style="--team:' + esc(cor) + '">⚽ ' + nome + (g.contra ? ' (contra)' : '') + ' ' + g.minuto + '\'</span>';
    }

    function histLado(L, venceu, saiu, direita) {
        return '<div class="hist-lado' + (direita ? ' dir' : '') + (venceu ? ' venceu' : '') + '" style="--team:' + esc(L.cor) + '">' +
            '<span class="team-dot"></span><div class="nome"><span>' + esc(L.nome) + '</span><small>' + esc(L.colete_nome) + (saiu ? ' · saiu' : '') + '</small></div></div>';
    }

    function cardHistorico(p) {
        var nomes = { a: p.a.colete_nome, b: p.b.colete_nome };
        var h = '<div class="hist-card"><div class="hist-top"><b>Partida ' + p.numero + '</b><span>' + esc(p.hora) + ' · ' + duracao(p.segundos) + (p.lado_vencedor ? '' : ' · empate') + '</span></div>' +
            '<div class="hist-placar">' + histLado(p.a, p.lado_vencedor === 'a', p.id_time_saiu === p.a.id_time, false) +
            '<div class="hist-nums">' + p.a.gols + ' × ' + p.b.gols + '</div>' +
            histLado(p.b, p.lado_vencedor === 'b', p.id_time_saiu === p.b.id_time, true) + '</div>';
        if (p.desempate) {
            h += '<div class="hist-sub">🎲 ' + esc(textoDesempateCurto(p.desempate, nomes)) + '</div>';
        }
        if (p.gols.length) {
            h += '<div class="hist-sub">' + p.gols.map(function (g) { return golTag(g, p); }).join('') + '</div>';
        }
        var emp = [];
        ['a', 'b'].forEach(function (l) {
            p[l].jogadores.forEach(function (j) { if (j.emprestado) { emp.push(esc(j.nome) + ' (do ' + esc(j.origem || 'time que saiu') + ')'); } });
        });
        if (emp.length) { h += '<div class="hist-sub">Vieram do time que saiu: ' + emp.join(', ') + '</div>'; }
        return h + '</div>';
    }

    function btnRelatorio() {
        return '<a class="btn-ios sec bloco mt-2" href="?tela=relatorio&id=' + J.id + '">Relatório completo: todas as partidas e cada jogador</a>';
    }

    function partHistorico() {
        if (!J.historico.length) { return ''; }
        var h = '<div class="sec-head-lg">Histórico da noite</div>' + btnRelatorio();

        if (J.artilheiros.length) {
            h += '<div class="sec-title">Artilheiros</div><div class="inset-list">';
            J.artilheiros.forEach(function (a, i) {
                h += '<div class="row-item"><div class="rank-pos' + (i < 3 ? ' top' : '') + '">' + (i + 1) + '</div>' +
                    '<div class="row-main"><div class="row-title">' + esc(a.nome) + '</div>' + (a.contra ? '<div class="row-sub">' + a.contra + ' contra</div>' : '') + '</div>' +
                    '<div class="rank-gols">' + a.gols + '<small>gol' + (a.gols === 1 ? '' : 's') + '</small></div></div>';
            });
            h += '</div>';
        }

        h += '<div class="sec-title">Partidas</div>';
        J.historico.forEach(function (p) { h += cardHistorico(p); });
        return h;
    }

    // ----- chegada e confirmações -----
    function partLink() {
        var h = '';
        h += '<div class="sec-head-lg">Confirmação</div>' +
            '<div class="card-ios"><h3>Link para o grupo do WhatsApp</h3><p class="mb-2">Cada jogador abre, informa o WhatsApp e confirma.</p>' +
            '<div class="link-box"><span style="flex:1">' + esc(J.link_publico) + '</span></div>' +
            '<div class="btn-row mt-3"><button class="btn-ios sec" data-act="copiar-link">Copiar link</button>' +
            '<a class="btn-ios verde" target="_blank" rel="noopener" href="' + esc(whatsappLink(textoConvite())) + '">Enviar no WhatsApp</a></div></div>';

        return h;
    }

    function cardGoleiros() {
        var g = J.goleiros;
        var resumo = g.qtd
            ? g.qtd + (g.qtd === 1 ? ' goleiro' : ' goleiros') + ' · ' + moeda(g.valor) + ' cada (' + moeda(g.total) + ')'
            : 'Nenhum goleiro contratado';
        return '<div class="sec-title">Goleiros contratados</div><div class="inset-list"><div class="row-item">' +
            '<div class="row-main"><div class="row-title">' + esc(resumo) + '</div>' +
            (g.nomes.length ? '<div class="row-sub wrap">' + esc(g.nomes.join(', ')) + '</div>' : '') + '</div>' +
            '<div class="row-end"><button class="btn-ios peq sec" data-act="goleiros">' + (g.qtd ? 'Alterar' : 'Informar') + '</button></div></div></div>' +
            '<div class="sec-foot">O valor dos goleiros entra junto com a quadra na divisão do fechamento.</div>';
    }

    function partListas() {
        var h = '';
        h += '<div class="btn-row mt-3"><button class="btn-ios sec" data-act="copiar-lista">Copiar lista</button>' +
            '<a class="btn-ios verde" target="_blank" rel="noopener" href="' + esc(whatsappLink(J.texto_lista)) + '">Enviar lista no WhatsApp</a></div>';
        h += '<div class="stat-row mt-3">' +
            '<div class="stat"><b>' + J.confirmados.length + '</b><small>Confirmados</small></div>' +
            '<div class="stat"><b>' + J.chegaram + '</b><small>Chegaram</small></div>' +
            '<div class="stat"><b>' + J.pendentes.length + '</b><small>Sem resposta</small></div></div>';

        // Confirmados
        h += '<div class="sec-title">Confirmados' + (J.confirmados.length ? '<button class="acao" data-act="todos-chegaram">Todos chegaram</button>' : '') + '</div>';
        if (J.confirmados.length) {
            h += '<div class="inset-list">';
            J.confirmados.forEach(function (p) {
                h += '<div class="row-item">' +
                    '<div class="num-chegada' + (p.chegou ? '' : ' vazio') + '">' + (p.chegou ? p.ordem_chegada : '–') + '</div>' +
                    '<div class="row-main"><div class="row-title">' + esc(p.nome) + '</div></div>' +
                    '<div class="row-end"><span class="score">' + virgula(p.score.toFixed(1)) + '</span>' +
                    '<button class="btn-ios peq ' + (p.chegou ? 'verde' : 'sec') + '" data-act="chegada" data-id="' + p.id_jogador + '" data-chegou="' + (p.chegou ? 0 : 1) + '" aria-pressed="' + p.chegou + '">' +
                    (p.chegou ? 'Chegou ✓' : 'Chegou') + '</button>' +
                    (J.situacao === 'agendado' ? '<button class="btn-plain perigo" data-act="remover" data-id="' + p.id_jogador + '" aria-label="Tirar ' + esc(p.nome) + ' da lista">' + ICONES.x + '</button>' : '') +
                    '</div></div>';
            });
            h += '</div>';
        } else {
            h += '<div class="inset-list"><div class="row-empty">Ninguém confirmou ainda. Envie o link no grupo.</div></div>';
        }

        // Pendentes (podem chegar sem confirmar)
        if (J.pendentes.length) {
            h += '<div class="sec-title">Sem resposta</div><div class="inset-list">';
            J.pendentes.forEach(function (p) {
                h += '<div class="row-item"><div class="row-main"><div class="row-title">' + esc(p.nome) + '</div></div>' +
                    '<div class="row-end"><button class="btn-ios peq sec" data-act="resp" data-resp="confirmado" data-id="' + p.id_jogador + '">Vai</button>' +
                    '<button class="btn-ios peq cinza" data-act="resp" data-resp="recusado" data-id="' + p.id_jogador + '">Não vai</button></div></div>';
            });
            h += '</div><div class="sec-foot">Quem chegar sem ter confirmado: toque em “Vai” e depois em “Chegou”.</div>';
        }

        h += cardGoleiros();

        // Recusados
        if (J.recusados.length) {
            h += '<div class="sec-title">Não vão</div><div class="inset-list">';
            J.recusados.forEach(function (p) {
                h += '<div class="row-item"><div class="row-main"><div class="row-title" style="opacity:.6">' + esc(p.nome) + '</div></div>' +
                    '<div class="row-end"><button class="btn-ios peq cinza" data-act="resp" data-resp="confirmado" data-id="' + p.id_jogador + '">Vai jogar</button></div></div>';
            });
            h += '</div>';
        }

        return h;
    }

    // ----- jogo finalizado: Pix e pagamentos -----
    function blocoFinalizado() {
        var f = J.fechamento;
        var h = '<div class="sec-head-lg">Cobrança</div>' +
            '<div class="card-ios text-center"><p>Cada jogador paga</p><div class="valor-grande">' + moeda(f.valor_por_jogador) + '</div>' +
            '<p class="mt-1">Quadra ' + moeda(f.valor_total) + (J.goleiros.qtd ? ' + ' + J.goleiros.qtd + (J.goleiros.qtd === 1 ? ' goleiro ' : ' goleiros ') + moeda(J.goleiros.total) : '') + ' · ' + nomeTime(f.total) + '</p>' +
            '<div class="mt-2"><span class="chip ' + (f.pagos === f.total ? 'verde' : 'laranja') + '">' + f.pagos + ' de ' + f.total + ' pagaram</span></div></div>';

        h += '<div class="sec-title">Pix copia e cola</div><div class="card-ios"><div class="pix-box" id="pixCodigo">' + esc(f.codigo_pix) + '</div>' +
            '<div class="btn-row mt-3"><button class="btn-ios sec" data-act="copiar-pix">Copiar código</button>' +
            '<a class="btn-ios verde" target="_blank" rel="noopener" href="' + esc(whatsappLink(f.mensagem_whatsapp)) + '">Enviar no WhatsApp</a></div></div>' +
            '<div class="sec-foot">O WhatsApp abre para você escolher o grupo (ou o contato). O mesmo código serve para todos.</div>';

        h += '<div class="sec-title">Quem já pagou</div><div class="inset-list">';
        f.pagamentos.forEach(function (p) {
            var msg = 'Oi, ' + p.nome.split(' ')[0] + '! ' + f.mensagem_whatsapp;
            h += '<div class="row-item"><div class="row-main"><div class="row-title">' + esc(p.nome) + '</div><div class="row-sub">' + moeda(p.valor) + '</div></div>' +
                '<div class="row-end">' +
                (p.pago ? '' : '<a class="btn-plain" style="font-size:15px" target="_blank" rel="noopener" href="' + esc(whatsappLink(msg, p.whatsapp)) + '">Cobrar</a>') +
                '<label class="ios-switch" title="Marcar como pago"><input type="checkbox" data-act="pago" data-id="' + p.id_jogador + '"' + (p.pago ? ' checked' : '') + ' aria-label="Pago por ' + esc(p.nome) + '"><span></span></label>' +
                '</div></div>';
        });
        h += '</div>';

        if (f.pagos === 0) {
            h += '<button class="btn-ios cinza bloco mt-3" data-act="fechar">Corrigir o valor</button>';
        }

        h += partHistorico();

        if (!J.pix_configurado) {
            h += '<div class="banner aviso mt-2">A chave Pix do grupo foi removida. O código acima continua válido.</div>';
        }
        return h;
    }

    // ---------- ações da tela ----------
    function iniciarPartida() {
        var X = J.proxima;
        var enviar = function () { chama('partida_iniciar', {}, aviso); };

        if (X && !X.primeira && X.faltam > 0) {
            confirmar('O ' + X.entra.nome + ' vai jogar com ' + X.entra.jogadores.length + ' de ' + X.por_time + ' jogadores. Iniciar assim mesmo?', 'Iniciar partida', false, enviar);
            return;
        }
        enviar();
    }

    $('#jogoConteudo').on('click', '[data-act]', function (e) {
        var $b = $(this);
        var act = $b.data('act');
        var id = $b.data('id');

        switch (act) {
            case 'chegada':
                chama('chegada', { id_jogador: id, chegou: $b.data('chegou') });
                break;
            case 'todos-chegaram':
                chama('todos_chegaram');
                break;
            case 'remover':
                chama('remover_presenca', { id_jogador: id });
                break;
            case 'resp':
                chama('responder', { id_jogador: id, resposta: $b.data('resp') });
                break;
            case 'sortear':
                if (J.sorteado) {
                    confirmar('Sortear de novo substitui os times e a lista de espera atuais.', 'Sortear novamente', false, function () { chama('sortear', {}, function (r) { alertTop('success', r.mensagem); }); });
                } else {
                    comTrava($b, chama('sortear', {}, function (r) { alertTop('success', r.mensagem); }));
                }
                break;
            case 'iniciar':
                iniciarPartida();
                break;
            case 'gol':
                abreGol(String($b.data('lado')));
                break;
            case 'gol-desfazer':
                chama('partida_gol_desfazer', { id_gol: $b.data('gol') }, aviso);
                break;
            case 'encerrar':
                abreEncerrar();
                break;
            case 'descartar':
                confirmar('Descartar a partida ' + J.partida_atual.numero + '? Os gols dela são ignorados e a lista de espera volta como estava antes de ela começar.', 'Descartar partida', true, function () {
                    chama('partida_descartar', { id_partida: J.partida_atual.id }, aviso);
                });
                break;
            case 'reabrir':
                confirmar('Reabrir a partida? O time que saiu volta para a quadra com o colete que tinha e sai da lista de espera.', 'Reabrir partida', false, function () {
                    chama('partida_reabrir', { id_partida: $b.data('partida') }, aviso);
                });
                break;
            case 'time-add':
                chama('time_adicionar', { id_jogador: id }, aviso);
                break;
            case 'mover':
                abreMover(id, String($b.data('nome')));
                break;
            case 'fechar':
                if (J.partida_atual) {
                    alertTop('error', 'Encerre ou descarte a partida em andamento antes de fechar o jogo.');
                } else {
                    abreFinalizar();
                }
                break;
            case 'copiar-lista':
                copiar(J.texto_lista, 'Lista copiada. É só colar no grupo');
                break;
            case 'goleiros':
                abreGoleiros();
                break;
            case 'copiar-link':
                copiar(J.link_publico, 'Link copiado');
                break;
            case 'copiar-pix':
                copiar(J.fechamento.codigo_pix, 'Código Pix copiado');
                break;
            case 'reativar':
                chama('reativar', {}, function (r) { alertTop('success', r.mensagem); });
                break;
            case 'excluir-cancelado':
                confirmar('Excluir este dia da agenda? Em grupos com jogo fixo, prefira manter como "sem jogo".', 'Excluir', true, excluirJogo);
                break;
        }
        e.stopPropagation();
    });

    $('#jogoConteudo').on('change', 'input[data-act=pago]', function () {
        chama('pagar', { id_jogador: $(this).data('id'), pago: this.checked ? 1 : 0 });
    });

    function excluirJogo() {
        api('jogo', 'excluir', { id: JOGO_ID }).done(function () { window.location.href = '?tela=grupo&id=' + J.id_grupo; });
    }

    // ---------- gol ----------
    function abreGol(lado) {
        golLado = lado;
        $('#tituloGol').text('Gol do colete ' + J.partida_atual[lado].colete_nome);
        $('#segGol button').removeClass('ativo').first().addClass('ativo');
        desenhaListaGol('normal');
        abrirFolha('#modalGol');
    }

    function desenhaListaGol(modo) {
        var P = J.partida_atual;
        if (!P) { return; }
        var contra = modo === 'contra';
        var ladoJog = contra ? (golLado === 'a' ? 'b' : 'a') : golLado;
        var h = '<div class="inset-list">';
        P[ladoJog].jogadores.forEach(function (p) {
            h += '<button type="button" class="row-item tappable" data-gol-jog="' + p.id_jogador + '" data-contra="' + (contra ? 1 : 0) + '">' +
                dot(P[ladoJog].cor) + '<div class="row-main"><div class="row-title">' + esc(p.nome) + '</div></div></button>';
        });
        if (!contra) {
            h += '<button type="button" class="row-item tappable" data-gol-jog="0" data-contra="0"><div class="row-main"><div class="row-title" style="color:var(--label-2)">Não sei quem fez</div></div></button>';
        }
        h += '</div>';
        h += contra
            ? '<div class="sec-foot">Escolha quem do colete ' + esc(P[ladoJog].colete_nome) + ' fez o gol contra. O gol vale para o colete ' + esc(P[golLado].colete_nome) + '.</div>'
            : '<div class="sec-foot">Quem fez o gol para o colete ' + esc(P[golLado].colete_nome) + '?</div>';
        $('#listaGol').html(h);
    }

    $('#segGol').on('click', 'button', function () {
        $('#segGol button').removeClass('ativo');
        $(this).addClass('ativo');
        desenhaListaGol(String($(this).data('valor')));
    });

    $('#listaGol').on('click', '[data-gol-jog]', function () {
        var $b = $(this);
        var dados = {
            id_partida: J.partida_atual.id,
            id_jogador: $b.data('gol-jog'),
            lado: golLado,
            contra: $b.data('contra')
        };
        $('#modalGol').modal('hide');
        chama('partida_gol', dados, aviso);
    });

    // ---------- encerrar partida ----------
    function abreEncerrar() {
        var P = J.partida_atual;
        var empate = P.a.gols === P.b.gols;
        var h = '<div class="placar-card"><div class="placar">' + ladoPlacar(P, 'a', false) +
            '<div class="placar-meio"><div class="placar-tempo">' + fmtTempo(Math.max(0, Math.floor((Date.now() - T0) / 1000))) + '</div><div class="placar-x">×</div></div>' +
            ladoPlacar(P, 'b', false) + '</div></div>';

        if (empate) {
            modoDesempate = 'par_impar';
            h += '<div class="banner aviso">' + ICONES.alerta + '<div>Deu empate. Como decidir quem sai da quadra?</div></div>' +
                '<div class="segmented mb-3" id="segDesempate"><button type="button" class="ativo" data-modo="par_impar">Par ou ímpar</button><button type="button" data-modo="manual">Escolher</button></div>';

            // Par ou ímpar: quem fica com o PAR; o outro fica com o ÍMPAR.
            h += '<div id="painelParImpar"><div class="sec-title" style="margin-top:0">Quem fica com o par?</div><div class="inset-list">';
            ['a', 'b'].forEach(function (l, i) {
                var L = P[l];
                h += '<label class="row-item tappable">' + dot(L.cor) +
                    '<div class="row-main"><div class="row-title">' + esc(L.colete_nome) + ' · ' + esc(L.nome) + '</div>' +
                    '<div class="row-sub">' + (l === 'a' ? 'o ' + esc(P.b.colete_nome) + ' fica com o ímpar' : 'o ' + esc(P.a.colete_nome) + ' fica com o ímpar') + '</div></div>' +
                    '<input type="radio" name="lado_par" value="' + l + '"' + (i === 0 ? ' checked' : '') + ' style="width:22px;height:22px;accent-color:var(--tint)"></label>';
            });
            h += '</div><div class="sec-foot">O app sorteia um número de 0 a 5 (os dedos) para cada time. Soma par: ganha quem ficou com o par. ' +
                'Soma ímpar: ganha o ímpar. <b>Quem ganha fica na quadra</b>; quem perde sai e vai para o fim da lista. O resultado fica no histórico.</div></div>';

            // Escolha manual
            h += '<div id="painelManual" class="hidden"><div class="inset-list">';
            ['a', 'b'].forEach(function (l) {
                var L = P[l];
                h += '<label class="row-item tappable">' + dot(L.cor) +
                    '<div class="row-main"><div class="row-title">Sai: ' + esc(L.nome) + ' · ' + esc(L.colete_nome) + '</div>' +
                    '<div class="row-sub">' + (L.sequencia ? L.sequencia + ' partida' + (L.sequencia === 1 ? '' : 's') + ' seguida' + (L.sequencia === 1 ? '' : 's') + ' na quadra' : 'acabou de entrar') + '</div></div>' +
                    '<input type="radio" name="id_time_saiu" value="' + L.id_time + '"' + (P.sai_padrao === l ? ' checked' : '') + ' style="width:22px;height:22px;accent-color:var(--tint)"></label>';
            });
            h += '</div><div class="sec-foot">Sugestão: sai quem está há mais partidas na quadra.</div></div>';
        } else {
            var v = P.a.gols > P.b.gols ? P.a : P.b, p = v === P.a ? P.b : P.a;
            h += '<div class="sec-foot" style="font-size:15px">O <b>' + esc(v.nome) + '</b> (colete ' + esc(v.colete_nome) + ') ganhou e fica. ' +
                'O <b>' + esc(p.nome) + '</b> sai e vai para o fim da lista; o próximo time sai dos primeiros da lista e veste o colete ' + esc(p.colete_nome) + '.</div>';
        }
        $('#encerrarCorpo').html(h);
        $('#btnEncerrarOk').text(empate ? 'Sortear' : 'Encerrar').prop('disabled', false);
        abrirFolha('#modalEncerrar');
    }

    $('#encerrarCorpo').on('click', '#segDesempate button', function () {
        modoDesempate = String($(this).data('modo'));
        $('#segDesempate button').removeClass('ativo');
        $(this).addClass('ativo');
        $('#painelParImpar').toggleClass('hidden', modoDesempate !== 'par_impar');
        $('#painelManual').toggleClass('hidden', modoDesempate !== 'manual');
        $('#btnEncerrarOk').text(modoDesempate === 'par_impar' ? 'Sortear' : 'Encerrar')
            .prop('disabled', modoDesempate === 'manual' && !$('input[name=id_time_saiu]:checked').length);
    });
    $('#encerrarCorpo').on('change', 'input[name=id_time_saiu]', function () { $('#btnEncerrarOk').prop('disabled', false); });

    $('#formEncerrar').on('submit', function (e) {
        e.preventDefault();
        var P = J.partida_atual;
        if (!P) { return; }
        var empate = P.a.gols === P.b.gols;
        var dados = { id_partida: P.id };
        if (empate && modoDesempate === 'par_impar') {
            dados.lado_par = $('input[name=lado_par]:checked').val() || 'a';
        } else if (empate) {
            dados.id_time_saiu = $('input[name=id_time_saiu]:checked').val() || 0;
        }
        comTrava($('#btnEncerrarOk'), chama('partida_encerrar', dados, function (r) {
            $('#modalEncerrar').modal('hide');
            aviso(r);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }));
    });

    // ---------- mexer na lista de espera ----------
    function abreMover(idJogador, nome) {
        moverJogador = idJogador;
        $('#tituloMover').text(nome.split(' ')[0]);

        var h = '<div class="inset-list">';
        var idx = -1;
        J.fila.forEach(function (p, i) { if (p.id_jogador === idJogador) { idx = i; } });

        var opcao = function (dir, titulo, sub, desligado) {
            return '<button type="button" class="row-item tappable" data-dir="' + dir + '"' + (desligado ? ' disabled style="opacity:.35"' : '') + '>' +
                '<div class="row-main"><div class="row-title">' + titulo + '</div>' + (sub ? '<div class="row-sub">' + sub + '</div>' : '') + '</div></button>';
        };

        if (idx >= 0) {
            var ultimo = idx === J.fila.length - 1;
            h += opcao('inicio', 'Primeiro da lista', 'entra no próximo time', idx === 0) +
                opcao('subir', 'Subir uma posição', idx > 0 ? 'passa para ' + (idx) + 'º' : '', idx === 0) +
                opcao('descer', 'Descer uma posição', !ultimo ? 'passa para ' + (idx + 2) + 'º' : '', ultimo) +
                opcao('fim', 'Último da lista', '', ultimo);
        } else if (!J.partida_atual) {
            h += opcao('fila', 'Tirar da quadra', 'vai para o fim da lista de espera', false);
        }
        h += '</div>';
        $('#listaMover').html(h);
        abrirFolha('#modalMover');
    }

    $('#listaMover').on('click', '[data-dir]:not([disabled])', function () {
        var dir = String($(this).data('dir'));
        $('#modalMover').modal('hide');
        chama('fila_mover', { id_jogador: moverJogador, direcao: dir });
    });

    // ---------- fechar jogo ----------
    function quantosPagam() {
        var n = jogadoresEmTimes();
        return n > 0 ? n : J.chegaram;
    }

    function atualizaPreview() {
        var qtd = Math.max(0, parseInt($('#fGolQtd').val(), 10) || 0);
        $('#linhaFGolValor').toggleClass('hidden', !qtd);
        var goleiros = qtd * lerMoeda($('#fGolValor').val());
        var v = lerMoeda($('#fValor').val()) + goleiros;
        var n = quantosPagam();
        if (!v || v <= 0 || !n) { $('#fPreview').html('Dividido igualmente entre quem jogou (' + nomeTime(n) + ').'); return; }
        var por = Math.ceil(Math.round(v * 100) / n) / 100;
        $('#fPreview').html('<b>' + moeda(por) + '</b> por jogador (' + nomeTime(n) + ')' +
            (goleiros ? ', total ' + moeda(v) + ' com os goleiros' : '') + '. Arredonda para cima para a quadra não ficar no prejuízo.');
    }

    function abreFinalizar() {
        var atual = J.fechamento ? J.fechamento.valor_total : (J.arena && J.arena.valor > 0 ? J.arena.valor : '');
        $('#fValor').val(valorCampoMoeda(atual));
        $('#fGolQtd').val(J.goleiros.qtd || '');
        $('#fGolValor').val(valorCampoMoeda(J.goleiros.valor));
        $('#tituloFinalizar').text(J.fechamento ? 'Corrigir valor' : 'Fechar jogo');
        atualizaPreview();
        var semTime = J.sem_time ? J.sem_time.length : 0;
        $('#fAviso').text(semTime ? semTime + (semTime === 1 ? ' jogador chegou' : ' jogadores chegaram') + ' e não entrou em nenhum time, por isso não será cobrado.' : '');
        abrirFolha('#modalFinalizar');
        if (!J.pix_configurado) { $('#erroFinalizar').text('Cadastre a chave Pix do grupo (Grupo › Ajustes) antes de gerar o código.'); }
    }

    // Delegado (depois do de app.js): roda com a máscara já aplicada.
    $(document).on('input', '#fValor, #fGolQtd, #fGolValor', atualizaPreview);
    $('#modalFinalizar').on('shown.bs.modal', function () { $('#fValor').trigger('focus').select(); });

    $('#formFinalizar').on('submit', function (e) {
        e.preventDefault();
        $('#erroFinalizar').text('');
        comTrava($('#formFinalizar button[type=submit]'),
            api('jogo', 'finalizar', {
                id: JOGO_ID, valor_total: $('#fValor').val(), goleiros_qtd: $('#fGolQtd').val() || 0,
                goleiros_valor: $('#fGolValor').val(), goleiros_nomes: J.goleiros.nomes.join(', ')
            }, { silencioso: true }))
            .done(function (res) {
                J = res.jogo;
                $('#modalFinalizar').modal('hide');
                alertTop('success', res.mensagem);
                render();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .fail(function (xhr) { $('#erroFinalizar').text((xhr.responseJSON || {}).error || 'Não foi possível fechar o jogo.'); });
    });

    // ---------- goleiros contratados ----------
    function abreGoleiros() {
        $('#gQtd').val(J.goleiros.qtd);
        $('#gValor').val(valorCampoMoeda(J.goleiros.valor));
        $('#gNomes').val(J.goleiros.nomes.join(', '));
        $('#erroGoleiros').text('');
        abrirFolha('#modalGoleiros');
    }

    $('#formGoleiros').on('submit', function (e) {
        e.preventDefault();
        $('#erroGoleiros').text('');
        var dados = Object.fromEntries(new FormData(this));
        dados.id = JOGO_ID;
        comTrava($('#formGoleiros button[type=submit]'), api('jogo', 'goleiros', dados, { silencioso: true }))
            .done(function (res) {
                J = res.jogo;
                $('#modalGoleiros').modal('hide');
                alertTop('success', res.mensagem);
                render();
            })
            .fail(function (xhr) { $('#erroGoleiros').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    // ---------- editar / sem jogo ----------
    $('#btnEditarJogo').on('click', function () {
        $('#eData').val(J.data);
        $('#eHora').val(J.hora);
        $('#ePorTime').val(J.jogadores_por_time);
        var h = '<option value="">Sem arena</option>';
        ARENAS.forEach(function (a) {
            h += '<option value="' + a.id + '"' + (J.id_arena === a.id ? ' selected' : '') + '>' + esc(a.nome) + '</option>';
        });
        $('#eArena').html(h);
        $('#btnExcluirJogo').toggleClass('hidden', J.situacao !== 'agendado');
        abrirFolha('#modalEditar');
    });

    $('#formEditar').on('submit', function (e) {
        e.preventDefault();
        $('#erroEditar').text('');
        var dados = Object.fromEntries(new FormData(this));
        dados.id = JOGO_ID;
        comTrava($('#formEditar button[type=submit]'), api('jogo', 'editar', dados, { silencioso: true }))
            .done(function (res) { J = res.jogo; $('#modalEditar').modal('hide'); alertTop('success', res.mensagem); render(); })
            .fail(function (xhr) { $('#erroEditar').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    $('#btnSemJogo').on('click', function () {
        $('#modalEditar').one('hidden.bs.modal', function () { $('#sMotivo').val(''); abrirFolha('#modalSemJogo'); }).modal('hide');
    });
    $('#modalSemJogo').on('shown.bs.modal', function () { $('#sMotivo').trigger('focus'); });
    $('#formSemJogo').on('submit', function (e) {
        e.preventDefault();
        $('#modalSemJogo').modal('hide');
        chama('cancelar', { motivo: $('#sMotivo').val() }, function (r) { alertTop('success', r.mensagem); });
    });

    $('#btnExcluirJogo').on('click', function () {
        $('#modalEditar').one('hidden.bs.modal', function () {
            confirmar('Excluir este jogo e as confirmações dele?', 'Excluir jogo', true, excluirJogo);
        }).modal('hide');
    });
});
