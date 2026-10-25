/* Grupo: jogos, jogadores, ranking e ajustes (também cria grupo novo) */
$(function () {
    var G = null;          // grupo carregado
    var ARENAS = [];
    var JOGADORES = [];

    // ---------- carregamento ----------
    api('grupo', 'carregar', { id_grupo: GRUPO_ID }).done(function (res) {
        ARENAS = res.arenas;
        preencheArenas($('#gArena'), 'Sem arena padrão');
        if (GRUPO_ID) {
            G = res.grupo;
            JOGADORES = res.jogadores;
            document.title = G.nome + ' · Futebol';
            $('#grupoTitulo').text(G.nome);
            $('#grupoSub').text((G.resumo_fixo ? G.resumo_fixo + ' · ' : '') + G.jogadores_por_time + ' por time');
            preencheFormGrupo(G);
            desenhaJogos(res.jogos);
            desenhaJogadores();
        }
    });

    function preencheArenas($sel, vazio, selecionada) {
        var h = '<option value="">' + esc(vazio) + '</option>';
        ARENAS.forEach(function (a) {
            h += '<option value="' + a.id + '"' + (String(selecionada) === String(a.id) ? ' selected' : '') + '>' + esc(a.nome) + '</option>';
        });
        $sel.html(h);
    }

    if (GRUPO_ID) {
        iniciaSegmentado($('#segGrupo'));
    }

    // ---------- coletes ----------
    var PALETA = [
        ['Laranja', '#FF9500'], ['Azul', '#007AFF'], ['Amarelo', '#FFCC00'], ['Verde', '#34C759'],
        ['Vermelho', '#FF3B30'], ['Rosa', '#FF2D55'], ['Roxo', '#AF52DE'], ['Turquesa', '#30B0C7'],
        ['Preto', '#1C1C1E'], ['Branco', '#FFFFFF'], ['Cinza', '#8E8E93']
    ];
    var COLETES = [['Laranja', '#FF9500'], ['Azul', '#007AFF']];

    function desenhaColetes() {
        [1, 2].forEach(function (n) {
            var atual = COLETES[n - 1], outro = COLETES[2 - n];
            var h = '';
            PALETA.forEach(function (c) {
                var igualOutro = c[1].toUpperCase() === outro[1].toUpperCase();
                h += '<button type="button" class="sw' + (c[1].toUpperCase() === atual[1].toUpperCase() ? ' ativo' : '') + '" style="--c:' + c[1] + '" ' +
                    'data-colete="' + n + '" data-nome="' + esc(c[0]) + '" data-cor="' + c[1] + '" role="radio" ' +
                    'aria-checked="' + (c[1].toUpperCase() === atual[1].toUpperCase()) + '" aria-label="' + esc(c[0]) + '"' + (igualOutro ? ' disabled' : '') + '></button>';
            });
            $('#paleta' + n).html(h);
            $('#cDot' + n).css('--team', atual[1]);
            $('#cNome' + n).text(atual[0]);
            $('#gColete' + n + 'Nome').val(atual[0]);
            $('#gColete' + n + 'Cor').val(atual[1]);
        });
    }
    $('#paleta1, #paleta2').on('click', '.sw', function () {
        var n = Number($(this).data('colete'));
        COLETES[n - 1] = [String($(this).data('nome')), String($(this).data('cor'))];
        desenhaColetes();
    });
    desenhaColetes();

    // ---------- ajustes do grupo ----------
    function alternaFixo() { $('#blocoFixo').toggleClass('hidden', !$('#gRecorrente').is(':checked')); }
    function alternaPix() { $('#blocoPix').toggleClass('hidden', !$('#gPixTipo').val()); }
    $('#gRecorrente').on('change', alternaFixo);
    $('#gPixTipo').on('change', alternaPix);
    alternaFixo(); alternaPix();

    function preencheFormGrupo(g) {
        $('#gNome').val(g.nome);
        $('#gPorTime').val(g.jogadores_por_time);
        $('#gRecorrente').prop('checked', g.recorrente === 's');
        if (g.dia_semana !== null) { $('#gDia').val(g.dia_semana); }
        if (g.horario) { $('#gHorario').val(g.horario); }
        preencheArenas($('#gArena'), 'Sem arena padrão', g.id_arena_padrao);
        $('#gPixTipo').val(g.pix_tipo || '');
        $('#gPixChave').val(g.pix_chave || '');
        $('#gPixNome').val(g.pix_nome || '');
        $('#gPixCidade').val(g.pix_cidade || '');
        if (g.coletes) {
            COLETES = [[g.coletes[0].nome, g.coletes[0].cor], [g.coletes[1].nome, g.coletes[1].cor]];
            desenhaColetes();
        }
        alternaFixo(); alternaPix();
    }

    $('#formGrupo').on('submit', function (e) {
        e.preventDefault();
        $('#erroGrupo').text('');
        var dados = {
            id: GRUPO_ID || '',
            nome: $('#gNome').val(),
            jogadores_por_time: $('#gPorTime').val(),
            recorrente: $('#gRecorrente').is(':checked') ? 's' : 'n',
            dia_semana: $('#gDia').val(),
            horario: $('#gHorario').val(),
            id_arena_padrao: $('#gArena').val(),
            pix_tipo: $('#gPixTipo').val(),
            pix_chave: $('#gPixChave').val(),
            pix_nome: $('#gPixNome').val(),
            pix_cidade: $('#gPixCidade').val(),
            colete1_nome: $('#gColete1Nome').val(),
            colete1_cor: $('#gColete1Cor').val(),
            colete2_nome: $('#gColete2Nome').val(),
            colete2_cor: $('#gColete2Cor').val()
        };
        comTrava($('#btnSalvarGrupo'), api('grupo', 'salvar', dados, { silencioso: true }))
            .done(function (res) {
                if (GRUPO_ID) {
                    alertTop('success', res.mensagem);
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    window.location.href = '?tela=grupo&id=' + res.id;
                }
            })
            .fail(function (xhr) { $('#erroGrupo').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    $('#btnExcluirGrupo').on('click', function () {
        confirmar('Excluir o grupo "' + G.nome + '"? Os jogos e o histórico deixam de aparecer.', 'Excluir grupo', true, function () {
            api('grupo', 'excluir', { id_grupo: GRUPO_ID }).done(function () { window.location.href = '?tela=grupos'; });
        });
    });

    // ---------- jogos ----------
    function desenhaJogos(j) {
        var h = '<div class="sec-title">Próximos</div>';
        h += blocoJogos(j.proximos, true, 'Nenhum jogo marcado. Crie um jogo ou ative o jogo fixo em Ajustes.');
        if (j.anteriores.length) {
            h += '<div class="sec-title">Anteriores</div>' + blocoJogos(j.anteriores, false, '');
        }
        $('#jogosLista').html(h);
    }

    function blocoJogos(lista, proximos, vazio) {
        if (!lista.length) { return '<div class="inset-list"><div class="row-empty">' + esc(vazio) + '</div></div>'; }
        var h = '<div class="inset-list">';
        lista.forEach(function (j) {
            var sub = (j.arena_nome ? j.arena_nome + ' · ' : '') + (j.situacao === 'cancelado' && j.motivo_cancelamento ? j.motivo_cancelamento : j.confirmados + ' confirmado' + (j.confirmados === 1 ? '' : 's'));
            var acao = '';
            if (proximos && j.situacao === 'agendado') { acao = '<button type="button" class="btn-plain" data-cancelar="' + j.id + '" data-quando="' + esc(j.data_fmt) + '" style="font-size:15px">Sem jogo</button>'; }
            if (proximos && j.situacao === 'cancelado') { acao = '<button type="button" class="btn-plain" data-reativar="' + j.id + '" style="font-size:15px">Reativar</button>'; }
            h += '<div class="row-item tappable" data-jogo="' + j.id + '">' +
                '<div class="row-main"><div class="row-title">' + esc(j.data_fmt) + '</div><div class="row-sub wrap">' + esc(sub) + '</div></div>' +
                '<div class="row-end">' + (j.situacao === 'agendado' ? '' : chipSituacao(j.situacao)) + acao + ICONES.seta + '</div></div>';
        });
        return h + '</div>';
    }

    function recarregaJogos() {
        api('grupo', 'jogos', { id_grupo: GRUPO_ID }).done(function (res) { desenhaJogos(res.jogos); });
    }

    $('#jogosLista').on('click', '.row-item', function (e) {
        var $alvo = $(e.target);
        if ($alvo.closest('[data-cancelar]').length) {
            var b = $alvo.closest('[data-cancelar]');
            confirmar('Marcar "' + b.data('quando') + '" como sem jogo? Ninguém poderá confirmar presença.', 'Sem jogo neste dia', true, function () {
                api('grupo', 'jogo_cancelar', { id: b.data('cancelar') }).done(function (r) { alertTop('success', r.mensagem); recarregaJogos(); });
            });
            return;
        }
        if ($alvo.closest('[data-reativar]').length) {
            api('grupo', 'jogo_reativar', { id: $alvo.closest('[data-reativar]').data('reativar') }).done(function (r) { alertTop('success', r.mensagem); recarregaJogos(); });
            return;
        }
        window.location.href = '?tela=jogo&id=' + $(this).data('jogo');
    });

    function dataLocal(d) {
        var m = ('0' + (d.getMonth() + 1)).slice(-2), dia = ('0' + d.getDate()).slice(-2);
        return d.getFullYear() + '-' + m + '-' + dia;
    }

    $('#btnNovoJogo').on('click', function () {
        // Sugere o próximo dia do jogo fixo (ou amanhã).
        var d = new Date(); d.setDate(d.getDate() + 1);
        if (G.recorrente === 's' && G.dia_semana !== null) {
            for (var i = 0; i < 7 && d.getDay() !== G.dia_semana; i++) { d.setDate(d.getDate() + 1); }
        }
        $('#jData').val(dataLocal(d));
        $('#jHora').val(G.horario || '20:00');
        preencheArenas($('#jArena'), 'Sem arena', G.id_arena_padrao);
        $('#jPorTime').val(G.jogadores_por_time);
        abrirFolha('#modalJogo');
    });

    $('#formJogo').on('submit', function (e) {
        e.preventDefault();
        $('#erroJogo').text('');
        var dados = Object.fromEntries(new FormData(this));
        dados.id_grupo = GRUPO_ID;
        comTrava($('#formJogo button[type=submit]'), api('grupo', 'jogo_criar', dados, { silencioso: true }))
            .done(function (res) { window.location.href = '?tela=jogo&id=' + res.id; })
            .fail(function (xhr) { $('#erroJogo').text((xhr.responseJSON || {}).error || 'Não foi possível criar o jogo.'); });
    });

    // ---------- ranking ----------
    var RANKING = null, rkPeriodo = 'tudo', rkOrdem = 'gols', rkCarregado = false;

    function carregaRanking() {
        $('#rankingLista').html('<div class="carregando"><span class="spin"></span>Carregando…</div>');
        api('grupo', 'ranking', { id_grupo: GRUPO_ID, periodo: rkPeriodo }).done(function (res) {
            RANKING = res.ranking;
            desenhaRanking();
        });
    }

    function desenhaRanking() {
        var r = RANKING, h = '';
        if (!r.jogadores.length) {
            $('#rankingLista').html('<div class="empty"><h3>Sem partidas ainda</h3><p>O ranking aparece depois que as partidas do jogo forem encerradas, com placar e gols.</p></div>');
            return;
        }
        h += '<div class="stat-row"><div class="stat"><b>' + r.totais.jogos + '</b><small>Jogos</small></div>' +
            '<div class="stat"><b>' + r.totais.partidas + '</b><small>Partidas</small></div>' +
            '<div class="stat"><b>' + r.totais.gols + '</b><small>Gols</small></div></div>';

        var lista = r.jogadores.slice().sort(function (a, b) {
            var d = b[rkOrdem] - a[rkOrdem];
            if (d) { return d; }
            d = b.gols - a.gols; if (d) { return d; }
            d = b.vitorias - a.vitorias; if (d) { return d; }
            return a.nome.localeCompare(b.nome, 'pt-BR');
        });

        var rotulo = { gols: 'gols', vitorias: 'vitórias', aproveitamento: 'aprov.', partidas: 'partidas' };
        h += '<div class="inset-list">';
        lista.forEach(function (j, i) {
            var valor = rkOrdem === 'aproveitamento' ? j.aproveitamento + '%' : j[rkOrdem];
            h += '<div class="row-item"><div class="rank-pos' + (i < 3 ? ' top' : '') + '">' + (i + 1) + '</div>' +
                '<div class="row-main"><div class="row-title">' + esc(j.nome) + '</div>' +
                '<div class="row-sub wrap">' + j.partidas + ' partida' + (j.partidas === 1 ? '' : 's') + ' · ' + j.vitorias + 'V ' + j.empates + 'E ' + j.derrotas + 'D · ' + j.aproveitamento + '%' +
                (j.gols_contra ? ' · ' + j.gols_contra + ' contra' : '') + '</div></div>' +
                '<div class="rank-gols">' + valor + '<small>' + rotulo[rkOrdem] + '</small></div></div>';
        });
        $('#rankingLista').html(h + '</div><div class="sec-foot">Aproveitamento = pontos conquistados (vitória 3, empate 1) sobre o total possível.</div>');
    }

    $('#segGrupo').on('click', 'button', function () {
        if ($(this).data('valor') === 'ranking' && !rkCarregado) { rkCarregado = true; carregaRanking(); }
    });
    $('#segPeriodo').on('click', 'button', function () {
        $('#segPeriodo button').removeClass('ativo'); $(this).addClass('ativo');
        rkPeriodo = String($(this).data('valor'));
        carregaRanking();
    });
    $('#segOrdem').on('click', 'button', function () {
        $('#segOrdem button').removeClass('ativo'); $(this).addClass('ativo');
        rkOrdem = String($(this).data('valor'));
        if (RANKING) { desenhaRanking(); }
    });

    // ---------- jogadores ----------
    function desenhaJogadores() {
        var ativos = JOGADORES.filter(function (j) { return j.ativo === 's'; }).length;
        var h = '';
        if (!JOGADORES.length) {
            $('#jogadoresLista').html('<div class="empty"><h3>Nenhum jogador</h3><p>Adicione os jogadores com WhatsApp e o nível de cada um (score). Eles também podem se cadastrar pelo link de confirmação.</p></div>');
            return;
        }
        h += '<div class="sec-title">' + ativos + ' ativo' + (ativos === 1 ? '' : 's') + ' · ' + JOGADORES.length + ' no total</div><div class="inset-list">';
        JOGADORES.forEach(function (j) {
            var partes = [formataTelefone(j.whatsapp)];
            if (j.instagram) { partes.push('@' + j.instagram); }
            if (j.convidado_por) { partes.push('convidado por ' + j.convidado_por); }
            h += '<div class="row-item tappable" data-jogador="' + j.id + '" style="' + (j.ativo === 'n' ? 'opacity:.55' : '') + '">' +
                '<div class="row-main"><div class="row-title">' + esc(j.nome) + '</div><div class="row-sub wrap">' + esc(partes.join(' · ')) + '</div></div>' +
                '<div class="row-end">' + (j.ativo === 'n' ? '<span class="chip">Inativo</span>' : '') + '<span class="score">' + j.score.toFixed(1).replace('.', ',') + '</span>' + ICONES.seta + '</div></div>';
        });
        $('#jogadoresLista').html(h + '</div>');
    }

    function recarregaJogadores() {
        api('grupo', 'jogadores', { id_grupo: GRUPO_ID }).done(function (res) { JOGADORES = res.jogadores; desenhaJogadores(); });
    }

    function preencheConvidou(selecionado, ignorarId) {
        var h = '<option value="">Ninguém</option>';
        JOGADORES.forEach(function (j) {
            if (j.id === ignorarId) { return; }
            h += '<option value="' + j.id + '"' + (selecionado === j.id ? ' selected' : '') + '>' + esc(j.nome) + '</option>';
        });
        $('#pConvidou').html(h);
    }

    function mostraScore() { $('#pScoreValor').text(Number($('#pScore').val()).toFixed(1).replace('.', ',')); }
    $('#pScore').on('input', mostraScore);

    function abrirJogador(j) {
        $('#tituloJogador').text(j ? 'Editar jogador' : 'Novo jogador');
        $('#pId').val(j ? j.id : '');
        $('#pNome').val(j ? j.nome : '');
        $('#pWhats').val(j ? formataTelefone(j.whatsapp) : '');
        $('#pInsta').val(j && j.instagram ? '@' + j.instagram : '');
        $('#pNasc').val(j && j.data_nascimento ? j.data_nascimento : '');
        $('#pScore').val(j ? j.score : 5);
        $('#pAtivo').prop('checked', j ? j.ativo === 's' : true);
        $('#blocoAtivo').toggleClass('hidden', !j);
        $('#btnRemoverJogador').toggleClass('hidden', !j);
        preencheConvidou(j ? j.id_convidado_por : null, j ? j.id : 0);
        mostraScore();
        abrirFolha('#modalJogador');
    }

    $('#btnNovoJogador').on('click', function () { abrirJogador(null); });
    $('#jogadoresLista').on('click', '.row-item', function () {
        var id = $(this).data('jogador');
        abrirJogador(JOGADORES.filter(function (j) { return j.id === id; })[0]);
    });
    $('#modalJogador').on('shown.bs.modal', function () { if (!$('#pId').val()) { $('#pNome').trigger('focus'); } });

    $('#formJogador').on('submit', function (e) {
        e.preventDefault();
        $('#erroJogador').text('');
        var dados = Object.fromEntries(new FormData(this));
        dados.id_grupo = GRUPO_ID;
        dados.ativo = $('#pAtivo').is(':checked') ? 's' : 'n';
        comTrava($('#formJogador button[type=submit]'), api('grupo', 'jogador_salvar', dados, { silencioso: true }))
            .done(function (res) {
                $('#modalJogador').modal('hide');
                alertTop('success', res.mensagem);
                recarregaJogadores();
            })
            .fail(function (xhr) { $('#erroJogador').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    $('#btnRemoverJogador').on('click', function () {
        var id = $('#pId').val(), nome = $('#pNome').val();
        $('#modalJogador').one('hidden.bs.modal', function () {
            confirmar('Remover ' + nome + ' do grupo? Quem já tem histórico de jogos fica apenas inativo.', 'Remover do grupo', true, function () {
                api('grupo', 'jogador_remover', { id_grupo: GRUPO_ID, id: id }).done(function (r) { alertTop('success', r.mensagem); recarregaJogadores(); });
            });
        }).modal('hide');
    });
});
