/* Página pública: o jogador confirma a presença pelo WhatsApp (sem login) */
$(function () {
    var J = null;
    var memoria = { whatsapp: '' };
    try { memoria.whatsapp = localStorage.getItem('futebol_whatsapp') || ''; } catch (e) { /* sem armazenamento */ }

    function chama(acao, dados) {
        return api('confirmar', acao, $.extend({ token: TOKEN }, dados || {}), { silencioso: true });
    }

    chama('info').done(function (res) { J = res.jogo; render(); })
        .fail(function (xhr) {
            $('#pubTitulo').text('Link inválido');
            $('#pubConteudo').html('<div class="banner erro"><div>' + esc((xhr.responseJSON || {}).error || 'Não foi possível abrir este link.') + '</div></div>');
        });

    function listaNomes() {
        if (!J.confirmados.length) { return ''; }
        var h = '<div class="sec-title">Quem vai (' + J.confirmados.length + ')</div><div class="card-ios"><div class="tag-nomes">';
        J.confirmados.forEach(function (n) { h += '<span class="chip">' + esc(n) + '</span>'; });
        return h + '</div></div>';
    }

    function render(resposta, nome) {
        $('#pubTitulo').text(J.grupo_nome);
        $('#pubSub').text(J.data_fmt);
        document.title = J.grupo_nome + ' · ' + J.data_fmt;

        var h = '';
        if (J.arena_nome) {
            h += '<div class="inset-list"><div class="row-item"><div class="row-icon laranja">' + ICONES.pin + '</div>' +
                '<div class="row-main"><div class="row-title">' + esc(J.arena_nome) + '</div><div class="row-sub wrap">' + esc(J.arena_endereco || '') + '</div></div>' +
                '<div class="row-end">' + (J.arena_url_mapa ? '<a class="btn-ios sec peq" href="' + esc(J.arena_url_mapa) + '" target="_blank" rel="noopener">Mapa</a>' : '') + '</div></div></div>';
        }

        if (J.situacao === 'cancelado') {
            h += '<div class="card-ios text-center"><div class="sucesso-icone neutro">' + ICONES.x + '</div><h3>Sem jogo neste dia</h3><p>' + esc(J.motivo_cancelamento || 'O administrador cancelou este jogo.') + '</p></div>';
        } else if (!J.aberto) {
            h += '<div class="card-ios text-center"><h3>Confirmações encerradas</h3><p>O jogo já começou ou terminou.</p></div>' + listaNomes();
        } else if (resposta) {
            h += '<div class="card-ios text-center"><div class="sucesso-icone' + (resposta === 'confirmado' ? '' : ' neutro') + '">' + (resposta === 'confirmado' ? ICONES.ok : ICONES.x) + '</div>' +
                '<h3>' + (resposta === 'confirmado' ? 'Presença confirmada!' : 'Combinado, você não vai') + '</h3>' +
                '<p>' + esc(nome) + ', ' + (resposta === 'confirmado' ? 'te esperamos lá.' : 'obrigado por avisar.') + '</p>' +
                '<button class="btn-plain mt-3" id="btnMudar">Mudar resposta</button></div>' + listaNomes();
        } else {
            h += '<form id="formResposta" autocomplete="on"><div class="sec-title">Seu WhatsApp</div><div class="inset-list">' +
                '<div class="field-row"><label for="rWhats">WhatsApp</label><input id="rWhats" name="whatsapp" type="tel" inputmode="tel" autocomplete="tel" placeholder="(62) 99999-8888" value="' + esc(mascaraTelefone(memoria.whatsapp)) + '" required></div>' +
                '<div class="field-row hidden" id="linhaNome"><label for="rNome">Seu nome</label><input id="rNome" name="nome" type="text" maxlength="120" autocomplete="name" placeholder="Como te chamam"></div></div>' +
                '<div class="field-note" id="notaNome">Use o número que o administrador cadastrou para você.</div>' +
                '<div class="input-erro" id="erroResposta" role="alert"></div>' +
                '<div class="resposta-btns"><button type="submit" class="btn-ios verde" data-resp="confirmado">Vou jogar</button>' +
                '<button type="submit" class="btn-ios cinza" data-resp="recusado" style="color:var(--red)">Não vou</button></div></form>' + listaNomes();
        }
        $('#pubConteudo').html(h);
    }

    var respostaEscolhida = 'confirmado';

    $('#pubConteudo').on('click', 'button[type=submit][data-resp]', function () { respostaEscolhida = $(this).data('resp'); });
    $('#pubConteudo').on('click', '#btnMudar', function () { render(); });

    $('#pubConteudo').on('submit', '#formResposta', function (e) {
        e.preventDefault();
        $('#erroResposta').text('');
        var $botoes = $(this).find('button[type=submit]');
        var dados = { whatsapp: $('#rWhats').val(), nome: $('#rNome').val(), resposta: respostaEscolhida };

        comTrava($botoes, chama('responder', dados))
            .done(function (res) {
                if (res.status === 'precisa_nome') {
                    $('#linhaNome').removeClass('hidden');
                    $('#notaNome').text('Não achamos esse número no grupo. Diga seu nome para entrar na lista.');
                    $('#rNome').trigger('focus');
                    return;
                }
                try { localStorage.setItem('futebol_whatsapp', dados.whatsapp); } catch (err) { /* ignora */ }
                memoria.whatsapp = dados.whatsapp;
                J = res.jogo;
                render(res.resposta, res.nome);
            })
            .fail(function (xhr) { $('#erroResposta').text((xhr.responseJSON || {}).error || 'Não foi possível enviar. Tente de novo.'); });
    });
});
