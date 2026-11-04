/* Jogo ao vivo (tela do jogador): entra com o telefone, vê a partida, o próximo time e a lista, e marca gols e assistências */
$(function () {
    var J = null;            // jogo (só com o jogador logado)
    var logado = false;
    var timerId = null;
    var T0 = 0;
    var golLado = 'a';
    var golDados = null;     // gol escolhido, esperando a assistência
    var buscando = false;
    var ultimo = '';         // última resposta (sem o tempo), para só redesenhar quando algo muda
    var memoria = '';
    try { memoria = localStorage.getItem('futebol_whatsapp') || ''; } catch (e) { /* sem armazenamento */ }

    function chama(acao, dados) {
        return api('aovivo', acao, $.extend({ token: TOKEN }, dados || {}), { silencioso: true });
    }

    function semTempo(obj) {
        return JSON.stringify(obj, function (k, v) { return k === 'segundos' ? undefined : v; });
    }

    function recebe(res, forcar) {
        logado = !!res.logado;
        var chave = semTempo(res);
        if (!forcar && chave === ultimo) { atualizaTempo(); return; }
        ultimo = chave;
        if (logado) { J = res.jogo; render(); } else { J = null; renderEntrar(res.jogo); }
    }

    function erroDe(xhr) { return (xhr.responseJSON || {}).error || 'Sem conexão. Tentando de novo…'; }

    function busca(forcar) {
        if (buscando) { return; }
        // Com uma folha aberta não redesenha: espera fechar.
        if (!forcar && $('.modal.show').length) { return; }
        buscando = true;
        chama('info').done(function (res) { recebe(res, forcar); $('#avOffline').remove(); })
            .fail(function (xhr) {
                if (xhr.status === 401) { recebe({ logado: false, jogo: null }, true); return; }
                if (!J && !$('#formEntrar').length) {
                    $('#avTitulo').text('Link inválido');
                    $('#avConteudo').html('<div class="banner erro"><div>' + esc(erroDe(xhr)) + '</div></div>');
                } else if (!$('#avOffline').length) {
                    $('#avConteudo').prepend('<div class="banner aviso" id="avOffline"><div>Sem conexão agora. A tela volta a se atualizar sozinha.</div></div>');
                }
            })
            .always(function () { buscando = false; });
    }

    busca(true);
    // Atualiza sozinho a cada 7 s com a tela à vista, e na hora em que o jogador volta para o navegador.
    setInterval(function () { if (!document.hidden) { busca(false); } }, 7000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { busca(false); } });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { busca(true); } });

    // ---------- utilidades ----------
    function fmtTempo(s) { var m = Math.floor(s / 60), r = s % 60; return ('0' + m).slice(-2) + ':' + ('0' + r).slice(-2); }
    function duracao(s) { return Math.max(1, Math.round(s / 60)) + ' min'; }
    function dot(cor) { return '<span class="team-dot" style="--team:' + esc(cor) + '"></span>'; }
    function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }
    function souEu(id) { return J && J.eu && id === J.eu.id; }
    function nomeEu(p) { return esc(p.nome) + (souEu(p.id_jogador) ? ' <span class="chip azul av-voce">você</span>' : ''); }

    function atualizaTempo() {
        if (!J || !J.partida_atual) { return; }
        T0 = Date.now() - J.partida_atual.segundos * 1000;
    }

    function iniciaTimer() {
        if (timerId) { clearInterval(timerId); timerId = null; }
        if (!J || !J.partida_atual) { return; }
        atualizaTempo();
        var tick = function () { $('#avTimer').text(fmtTempo(Math.max(0, Math.floor((Date.now() - T0) / 1000)))); };
        tick();
        timerId = setInterval(tick, 1000);
    }

    // ---------- entrar ----------
    function renderEntrar(cab) {
        if (timerId) { clearInterval(timerId); timerId = null; }
        $('#avEu').html('');
        if (cab) {
            $('#avTitulo').text(cab.grupo_nome);
            $('#avSub').text(cab.data_fmt + (cab.arena_nome ? ' · ' + cab.arena_nome : ''));
            document.title = cab.grupo_nome + ' · Jogo ao vivo';
        }
        var h = '<form id="formEntrar" autocomplete="on"><div class="card-ios"><h3>Entre para acompanhar o jogo</h3>' +
            '<p>Veja a partida, o próximo time e a lista de espera, e marque quem fez o gol e quem deu a assistência.</p></div>' +
            '<div class="sec-title">Seu telefone</div><div class="inset-list">' +
            '<div class="field-row"><label for="avTel">Telefone</label><input id="avTel" name="telefone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(62) 99999-8888" value="' + esc(mascaraTelefone(memoria)) + '" required></div></div>' +
            '<div class="field-note">Use o número que o administrador cadastrou para você no grupo. Você continua conectado neste celular.</div>' +
            '<div class="input-erro" id="erroEntrar" role="alert"></div>' +
            '<button type="submit" class="btn-ios bloco mt-3">Entrar</button></form>';
        $('#avConteudo').html(h);
    }

    $('#avConteudo').on('submit', '#formEntrar', function (e) {
        e.preventDefault();
        $('#erroEntrar').text('');
        var tel = $('#avTel').val();
        comTrava($(this).find('button[type=submit]'), chama('entrar', { telefone: tel }))
            .done(function (res) {
                try { localStorage.setItem('futebol_whatsapp', tel); } catch (err) { /* ignora */ }
                memoria = tel;
                recebe(res, true);
                window.scrollTo(0, 0);
            })
            .fail(function (xhr) { $('#erroEntrar').text(erroDe(xhr)); });
    });

    $('#avEu').on('click', '#btnSair', function () {
        confirmar('Sair deste celular? Para voltar, é só informar o telefone de novo.', 'Sair', true, function () {
            chama('sair').done(function (res) { recebe(res, true); });
        });
    });

    // ---------- tela do jogo ----------
    function render() {
        $('#avTitulo').text(J.grupo_nome);
        $('#avSub').html(esc(J.data_fmt) + (J.arena_nome ? ' · ' + esc(J.arena_nome) : '') + ' &nbsp;' + chipSituacao(J.situacao));
        document.title = J.grupo_nome + ' · Jogo ao vivo';
        $('#avEu').html('<button type="button" class="btn-plain av-eu" id="btnSair" title="Sair">' + esc(J.eu.nome.split(' ')[0]) + ' · Sair</button>');

        var h = '';
        if (J.admin_url) {
            h += '<a class="btn-ios sec bloco mb-3" href="' + esc(J.admin_url) + '">Você é administrador: abrir a tela do jogo</a>';
        }
        h += ondeEstou();

        if (J.situacao === 'cancelado') {
            h += '<div class="card-ios text-center"><h3>Sem jogo neste dia</h3><p>O administrador cancelou este jogo.</p></div>';
        } else if (J.situacao === 'finalizado') {
            h += '<div class="card-ios text-center"><h3>Jogo encerrado</h3><p>Valeu pelo jogo! Veja abaixo como foi a noite.</p></div>';
        } else if (!J.sorteado) {
            h += '<div class="card-ios text-center"><h3>Times ainda não sorteados</h3><p>Assim que o administrador sortear, os times e a lista de espera aparecem aqui.</p></div>' + secConfirmados();
        } else if (J.partida_atual) {
            h += blocoAoVivo(J.partida_atual);
        } else if (J.proxima) {
            h += blocoAguardando(J.proxima);
        } else {
            h += '<div class="card-ios text-center"><h3>Aguardando a próxima partida</h3><p>O administrador está ajustando os times.</p></div>';
        }

        if (J.sorteado && J.situacao !== 'cancelado') {
            h += secProximo() + secFila();
        }
        h += secArtilheiros() + secHistorico();
        h += '<div class="sec-foot text-center mt-3">A tela se atualiza sozinha. Só o administrador sorteia, encerra a partida, tira o par ou ímpar e fecha o jogo.</div>';

        $('#avConteudo').html(h);
        iniciaTimer();
    }

    // Faixa no topo: onde o jogador logado está agora.
    function ondeEstou() {
        if (!J.sorteado || !J.aberto) { return ''; }
        var P = J.partida_atual, txt = '', cor = null;
        if (P) {
            ['a', 'b'].forEach(function (l) {
                P[l].jogadores.forEach(function (p) { if (souEu(p.id_jogador)) { txt = 'Você está em quadra com o colete ' + P[l].colete_nome; cor = P[l].cor; } });
            });
        }
        if (!txt && J.proxima && J.proxima.primeira && !P) {
            [J.proxima.lado_a, J.proxima.lado_b].forEach(function (L) {
                L.jogadores.forEach(function (p) { if (souEu(p.id_jogador)) { txt = 'Você joga a primeira partida (colete ' + L.colete_nome + ')'; cor = L.cor; } });
            });
        }
        if (!txt && J.proxima && J.proxima.entra && !P) {
            J.proxima.entra.jogadores.forEach(function (p) { if (souEu(p.id_jogador)) { txt = 'Você entra na próxima partida (colete ' + J.proxima.entra.colete_nome + ')'; cor = J.proxima.entra.cor; } });
        }
        if (!txt) {
            // Quem perde vai para o fim da lista, então a posição só melhora: cada bloco de
            // "jogadores por time" entra numa partida, na ordem.
            var depois = (!P && J.proxima && J.proxima.primeira) ? 1 : 0; // antes da 1ª partida, o 1º bloco entra na 2ª
            J.fila.forEach(function (f) {
                if (!souEu(f.id_jogador)) { return; }
                var n = f.bloco + depois;
                txt = 'Você é o ' + f.posicao + 'º da lista de espera · entra ' + (n === 1 ? 'na próxima partida' : 'daqui a ' + n + ' partidas');
            });
        }
        if (!txt) { return ''; }
        return '<div class="av-onde"' + (cor ? ' style="--team:' + esc(cor) + '"' : '') + '>' + (cor ? '<span class="team-dot"></span>' : '') + '<span>' + esc(txt) + '</span></div>';
    }

    function ladoPlacar(P, l) {
        var L = P[l];
        return '<div class="placar-lado" style="--team:' + esc(L.cor) + '">' +
            '<div class="placar-colete"><span class="team-dot"></span><span>' + esc(L.colete_nome) + '</span></div>' +
            '<div class="placar-time">' + esc(L.nome) + '</div>' +
            '<div class="placar-gols">' + L.gols + '</div>' +
            (J.aberto ? '<button type="button" class="btn-ios peq sec" data-act="gol" data-lado="' + l + '">+ Gol</button>' : '') + '</div>';
    }

    function elencoCol(L) {
        var h = '<div class="elenco-col" style="--team:' + esc(L.cor) + '">';
        L.jogadores.forEach(function (p) { h += '<div' + (souEu(p.id_jogador) ? ' class="av-eu-linha"' : '') + '>' + esc(p.nome) + '</div>'; });
        return h + '</div>';
    }

    function blocoAoVivo(P) {
        var h = '<div class="sec-head-lg"><span>Partida ' + P.numero + '</span><span class="chip laranja">Ao vivo</span></div>' +
            '<div class="placar-card"><div class="placar">' + ladoPlacar(P, 'a') +
            '<div class="placar-meio"><div class="placar-tempo" id="avTimer">00:00</div><div class="placar-x">×</div></div>' +
            ladoPlacar(P, 'b') + '</div>' +
            '<div class="elencos">' + elencoCol(P.a) + elencoCol(P.b) + '</div></div>';

        h += '<div class="sec-title">Gols</div><div class="inset-list">';
        if (P.gols.length) {
            P.gols.slice().reverse().forEach(function (g) {
                var titulo = g.nome ? esc(g.nome) : '<span style="color:var(--label-2)">Autor não informado</span>';
                var sub = g.contra ? 'Gol contra · vale para o colete ' + esc(P[g.lado].colete_nome) : 'Colete ' + esc(P[g.lado].colete_nome);
                if (g.assistencia_nome) { sub += ' · assistência de ' + esc(g.assistencia_nome); }
                sub += g.registrou_nome ? ' · marcado por ' + (souEu(g.id_registrou) ? 'você' : esc(g.registrou_nome)) : ' · marcado pelo administrador';
                h += '<div class="row-item"><div class="min-gol">' + g.minuto + '\'</div>' + dot(P[g.lado].cor) +
                    '<div class="row-main"><div class="row-title">' + titulo + '</div><div class="row-sub wrap">' + sub + '</div></div>' +
                    (souEu(g.id_registrou) ? '<div class="row-end"><button type="button" class="btn-plain perigo" data-act="gol-desfazer" data-gol="' + g.id + '" aria-label="Desfazer este gol">' + ICONES.x + '</button></div>' : '') + '</div>';
            });
        } else {
            h += '<div class="row-empty">Nenhum gol ainda. Toque em “+ Gol” no colete que marcou.</div>';
        }
        return h + '</div>';
    }

    function blocoAguardando(X) {
        var h = '<div class="sec-head-lg"><span>Intervalo</span><span class="chip azul">Próxima partida</span></div>';
        if (X.ultima) {
            var U = X.ultima;
            h += '<div class="card-ios"><div>Partida ' + U.numero + ': ' + esc(U.colete_nome_a) + ' <b>' + U.gols_a + ' × ' + U.gols_b + '</b> ' + esc(U.colete_nome_b) + '</div>' +
                '<div class="row-sub">Saiu: ' + esc(U.saiu) + ' (colete ' + esc(U.colete_saiu) + ')</div></div>';
        }
        h += '<div class="card-ios text-center"><p class="mb-0">Esperando o administrador iniciar a próxima partida.</p></div>';
        return h;
    }

    function listaJogadores(L, rotulo) {
        var h = '<div class="inset-list"><div class="row-item">' + dot(L.cor) + '<div class="row-main"><div class="row-title">' + esc(rotulo) + '</div>' +
            '<div class="row-sub">' + esc(L.nome) + ' · ' + plural(L.jogadores.length, 'jogador', 'jogadores') + '</div></div></div>';
        L.jogadores.forEach(function (p) {
            h += '<div class="row-item"><div class="row-main"><div class="row-title">' + nomeEu(p) + '</div>' +
                (p.veio_do_saiu ? '<div class="row-sub">veio do ' + esc(p.origem || 'time que saiu') + '</div>' : '') + '</div></div>';
        });
        return h + '</div>';
    }

    // Próximo time: sem partida em andamento é o plano do app; com partida, são os primeiros da lista.
    function secProximo() {
        if (!J.aberto) { return ''; }
        var X = J.proxima;
        if (!J.partida_atual && X) {
            if (X.primeira) {
                return '<div class="sec-title">Primeira partida</div>' + listaJogadores(X.lado_a, 'Colete ' + X.lado_a.colete_nome) + listaJogadores(X.lado_b, 'Colete ' + X.lado_b.colete_nome);
            }
            if (X.erro) { return '<div class="banner aviso mt-3"><div>' + esc(X.erro) + '</div></div>'; }
            if (X.entra) {
                return '<div class="sec-title">Próximo time a entrar</div>' + listaJogadores(X.entra, 'Entra com o colete ' + X.entra.colete_nome) +
                    (X.fica ? '<div class="sec-foot">Fica na quadra: ' + esc(X.fica.nome) + ' (colete ' + esc(X.fica.colete_nome) + ').</div>' : '');
            }
            return '';
        }
        if (J.partida_atual && J.fila.length) {
            var proximos = J.fila.filter(function (f) { return f.bloco === 1; });
            var h = '<div class="sec-title">Próximos a entrar</div><div class="inset-list">';
            proximos.forEach(function (f) { h += '<div class="row-item"><div class="rank-pos">' + f.posicao + '</div><div class="row-main"><div class="row-title">' + nomeEu(f) + '</div></div></div>'; });
            h += '</div><div class="sec-foot">Entram no lugar de quem perder esta partida' +
                (proximos.length < J.jogadores_por_time ? ' (o time se completa com quem sair)' : '') + '.</div>';
            return h;
        }
        return '';
    }

    function secFila() {
        if (!J.aberto) { return ''; }
        var fila = J.fila;
        // Sem partida em andamento, os que vão entrar já aparecem em "Próximo time".
        if (!J.partida_atual && J.proxima && J.proxima.fila_restante) {
            var restantes = {};
            J.proxima.fila_restante.forEach(function (f) { restantes[f.id_jogador] = true; });
            fila = fila.filter(function (f) { return restantes[f.id_jogador]; });
        } else if (J.partida_atual) {
            fila = fila.filter(function (f) { return f.bloco > 1; }); // o 1º bloco já está em "Próximos a entrar"
        }
        if (!fila.length) { return ''; }
        var h = '<div class="sec-title">Lista de espera (' + fila.length + ')</div><div class="inset-list">';
        fila.forEach(function (f) {
            h += '<div class="row-item"><div class="rank-pos">' + f.posicao + '</div><div class="row-main"><div class="row-title">' + nomeEu(f) + '</div>' +
                (f.saiu_partida ? '<div class="row-sub">saiu na partida ' + f.saiu_partida + '</div>' : '') + '</div></div>';
        });
        return h + '</div>';
    }

    function secConfirmados() {
        if (!J.confirmados.length) { return ''; }
        var chegaram = J.confirmados.filter(function (c) { return c.chegou; }).length;
        var h = '<div class="sec-title">Confirmados (' + J.confirmados.length + (chegaram ? ' · ' + chegaram + ' chegaram' : '') + ')</div><div class="card-ios"><div class="tag-nomes">';
        J.confirmados.forEach(function (c) { h += '<span class="chip' + (c.chegou ? ' verde' : '') + '">' + esc(c.nome) + '</span>'; });
        return h + '</div></div>';
    }

    function secArtilheiros() {
        if (!J.artilheiros.length) { return ''; }
        var h = '<div class="sec-title">Artilheiros e assistências da noite</div><div class="inset-list">';
        J.artilheiros.forEach(function (a, i) {
            var sub = [];
            if (a.assistencias) { sub.push(plural(a.assistencias, 'assistência', 'assistências')); }
            if (a.contra) { sub.push(a.contra + ' contra'); }
            h += '<div class="row-item"><div class="rank-pos' + (i < 3 && a.gols ? ' top' : '') + '">' + (i + 1) + '</div>' +
                '<div class="row-main"><div class="row-title">' + nomeEu(a) + '</div>' + (sub.length ? '<div class="row-sub">' + sub.join(' · ') + '</div>' : '') + '</div>' +
                '<div class="rank-gols">' + a.gols + '<small>gol' + (a.gols === 1 ? '' : 's') + '</small></div></div>';
        });
        return h + '</div>';
    }

    function golTag(g, p) {
        return '<span class="gol-tag" style="--team:' + esc(p[g.lado].cor) + '">⚽ ' + (g.nome ? esc(g.nome) : 'Gol sem autor') + (g.contra ? ' (contra)' : '') +
            (g.assistencia_nome ? ' (assist. ' + esc(g.assistencia_nome) + ')' : '') + ' ' + g.minuto + '\'</span>';
    }

    function secHistorico() {
        if (!J.historico.length) { return ''; }
        var h = '<div class="sec-title">Partidas da noite (' + J.total_partidas + ')</div>';
        J.historico.forEach(function (p) {
            h += '<div class="hist-card"><div class="hist-top"><b>Partida ' + p.numero + '</b><span>' + esc(p.hora) + ' · ' + duracao(p.segundos) + (p.lado_vencedor ? '' : ' · empate') + '</span></div>' +
                '<div class="hist-placar">' + histLado(p.a, p.lado_vencedor === 'a', p.lado_saiu === 'a', false) +
                '<div class="hist-nums">' + p.a.gols + ' × ' + p.b.gols + '</div>' + histLado(p.b, p.lado_vencedor === 'b', p.lado_saiu === 'b', true) + '</div>';
            if (p.desempate && p.desempate.tipo === 'par_impar') {
                var D = p.desempate;
                h += '<div class="hist-sub">🎲 Par ou ímpar: ' + D.num_a + ' + ' + D.num_b + ' = ' + D.soma + ', deu ' + (D.resultado === 'par' ? 'par' : 'ímpar') +
                    ' · ' + esc(p[D.lado_ganhou].colete_nome) + ' ficou</div>';
            }
            if (p.gols.length) { h += '<div class="hist-sub">' + p.gols.map(function (g) { return golTag(g, p); }).join('') + '</div>'; }
            h += '</div>';
        });
        if (J.total_partidas > J.historico.length) { h += '<div class="sec-foot">Mostrando as ' + J.historico.length + ' últimas.</div>'; }
        return h;
    }

    function histLado(L, venceu, saiu, direita) {
        return '<div class="hist-lado' + (direita ? ' dir' : '') + (venceu ? ' venceu' : '') + '" style="--team:' + esc(L.cor) + '">' +
            '<span class="team-dot"></span><div class="nome"><span>' + esc(L.colete_nome) + '</span><small>' + esc(L.nome) + (saiu ? ' · saiu' : '') + '</small></div></div>';
    }

    // ---------- ações ----------
    $('#avConteudo').on('click', '[data-act]', function () {
        var $b = $(this);
        switch ($b.data('act')) {
            case 'gol':
                abreGol(String($b.data('lado')));
                break;
            case 'gol-desfazer':
                confirmar('Desfazer este gol?', 'Desfazer gol', true, function () {
                    chama('gol_desfazer', { id_gol: $b.data('gol') }).done(function (res) { alertTop('success', res.mensagem); recebe(res, true); })
                        .fail(function (xhr) { alertTop('error', erroDe(xhr)); });
                });
                break;
        }
    });

    function abreGol(lado) {
        if (!J.partida_atual) { return; }
        golLado = lado;
        golDados = null;
        $('#tituloGolAv').text('Gol do colete ' + J.partida_atual[lado].colete_nome);
        $('#segGolAv').removeClass('hidden').find('button').removeClass('ativo').first().addClass('ativo');
        desenhaListaGol('normal');
        abrirFolha('#modalGolAv');
    }

    function desenhaListaGol(modo) {
        var P = J.partida_atual;
        if (!P) { return; }
        var contra = modo === 'contra';
        var ladoJog = contra ? (golLado === 'a' ? 'b' : 'a') : golLado;
        var h = '<div class="inset-list">';
        P[ladoJog].jogadores.forEach(function (p) {
            h += '<button type="button" class="row-item tappable" data-gol-jog="' + p.id_jogador + '" data-contra="' + (contra ? 1 : 0) + '">' +
                dot(P[ladoJog].cor) + '<div class="row-main"><div class="row-title">' + nomeEu(p) + '</div></div></button>';
        });
        if (!contra) {
            h += '<button type="button" class="row-item tappable" data-gol-jog="0" data-contra="0"><div class="row-main"><div class="row-title" style="color:var(--label-2)">Não sei quem fez</div></div></button>';
        }
        h += '</div>';
        h += contra
            ? '<div class="sec-foot">Escolha quem do colete ' + esc(P[ladoJog].colete_nome) + ' fez o gol contra. O gol vale para o colete ' + esc(P[golLado].colete_nome) + '.</div>'
            : '<div class="sec-foot">Quem fez o gol para o colete ' + esc(P[golLado].colete_nome) + '?</div>';
        $('#listaGolAv').html(h);
    }

    $('#segGolAv').on('click', 'button', function () {
        $('#segGolAv button').removeClass('ativo');
        $(this).addClass('ativo');
        desenhaListaGol(String($(this).data('valor')));
    });

    $('#listaGolAv').on('click', '[data-gol-jog]', function () {
        var $b = $(this);
        golDados = { id_partida: J.partida_atual.id, id_jogador: Number($b.data('gol-jog')), lado: golLado, contra: Number($b.data('contra')) };
        if (golDados.contra) { enviaGol(0); return; }
        desenhaAssistencia();
    });

    function desenhaAssistencia() {
        var P = J.partida_atual;
        var autor = null;
        P[golLado].jogadores.forEach(function (p) { if (p.id_jogador === golDados.id_jogador) { autor = p.nome; } });
        $('#segGolAv').addClass('hidden');
        var h = '<div class="sec-foot" style="padding-top:0">Gol de <b>' + esc(autor || 'autor não informado') + '</b>. Quem deu a assistência?</div><div class="inset-list">';
        P[golLado].jogadores.forEach(function (p) {
            if (p.id_jogador === golDados.id_jogador) { return; }
            h += '<button type="button" class="row-item tappable" data-assist="' + p.id_jogador + '">' + dot(P[golLado].cor) +
                '<div class="row-main"><div class="row-title">' + nomeEu(p) + '</div></div></button>';
        });
        h += '<button type="button" class="row-item tappable" data-assist="0"><div class="row-main"><div class="row-title" style="color:var(--label-2)">Sem assistência / não sei</div></div></button></div>';
        $('#listaGolAv').html(h);
    }

    $('#listaGolAv').on('click', '[data-assist]', function () { enviaGol(Number($(this).data('assist'))); });

    function enviaGol(idAssist) {
        var dados = $.extend({}, golDados, { id_assistencia: idAssist });
        golDados = null;
        var $botoes = $('#listaGolAv button').prop('disabled', true);
        chama('gol', dados).done(function (res) {
            $('#modalGolAv').modal('hide');
            alertTop('success', res.mensagem);
            recebe(res, true);
        }).fail(function (xhr) {
            $botoes.prop('disabled', false);
            $('#modalGolAv').modal('hide');
            alertTop('error', erroDe(xhr));
            busca(true);
        });
    }

    // Folha fechada: atualiza na hora (pode ter chegado gol de outra pessoa enquanto ela estava aberta).
    $('#modalGolAv').on('hidden.bs.modal', function () { busca(false); });
});
