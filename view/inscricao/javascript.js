/* Página pública: o jogador se cadastra no grupo pelo link (fica pendente até o admin aprovar) */
$(function () {
    var G = null;

    function chama(acao, dados) {
        return api('inscricao', acao, $.extend({ token: TOKEN }, dados || {}), { silencioso: true });
    }

    chama('info').done(function (res) { G = res.grupo; render(); })
        .fail(function (xhr) {
            $('#pubTitulo').text('Link inválido');
            $('#pubConteudo').html('<div class="banner erro"><div>' + esc((xhr.responseJSON || {}).error || 'Não foi possível abrir este link.') + '</div></div>');
        });

    var RESULTADO = {
        enviado: ['ok', 'Pedido enviado!', 'Agora é só esperar o administrador aprovar. Depois você recebe os links dos jogos para confirmar presença.'],
        ja_pendente: ['ok', 'Pedido já enviado', 'Seu pedido para entrar no grupo já está com o administrador. Aguarde a aprovação.'],
        ja_membro: ['ok', 'Você já está no grupo', 'Seu WhatsApp já faz parte deste grupo. É só confirmar presença pelo link de cada jogo.'],
        inativo: ['x', 'Cadastro inativo', 'Seu cadastro está inativo neste grupo. Fale com o administrador.']
    };

    function render(res) {
        $('#pubTitulo').text(G.grupo_nome);
        $('#pubSub').text(G.resumo_fixo || 'Cadastro de jogador');
        document.title = G.grupo_nome + ' · Entrar no grupo';

        var h = '';
        if (res) {
            var r = RESULTADO[res.status] || RESULTADO.enviado;
            h += '<div class="card-ios text-center"><div class="sucesso-icone' + (r[0] === 'ok' ? '' : ' neutro') + '">' + (r[0] === 'ok' ? ICONES.ok : ICONES.x) + '</div>' +
                '<h3>' + esc(r[1]) + '</h3><p>' + esc(res.nome) + ', ' + esc(r[2].charAt(0).toLowerCase() + r[2].slice(1)) + '</p></div>';
        } else if (!G.aberta) {
            h += '<div class="card-ios text-center"><div class="sucesso-icone neutro">' + ICONES.x + '</div><h3>Inscrições fechadas</h3><p>O grupo não está aceitando novos jogadores agora. Fale com o administrador.</p></div>';
        } else {
            h += '<form id="formInscricao" autocomplete="on"><div class="sec-title">Seus dados</div><div class="inset-list">' +
                '<div class="field-row"><label for="iNome">Nome</label><input id="iNome" name="nome" type="text" maxlength="120" autocomplete="name" placeholder="Como te chamam" required></div>' +
                '<div class="field-row"><label for="iWhats">WhatsApp</label><input id="iWhats" name="whatsapp" type="tel" inputmode="tel" autocomplete="tel" placeholder="(62) 99999-8888" maxlength="20" required></div>' +
                '<div class="field-row"><label for="iInsta">Instagram</label><input id="iInsta" name="instagram" type="text" maxlength="61" placeholder="@usuario (opcional)" autocapitalize="none"></div>' +
                '<div class="field-row"><label for="iNasc">Nascimento</label><input id="iNasc" name="data_nascimento" type="date"></div></div>' +
                '<div class="field-note">O administrador do grupo aprova o seu cadastro. Use o WhatsApp em que você recebe os links dos jogos.</div>' +
                '<div class="input-erro" id="erroInscricao" role="alert"></div>' +
                '<button type="submit" class="btn-ios verde bloco mt-3">Quero entrar no grupo</button></form>';
        }
        $('#pubConteudo').html(h);
    }

    $('#pubConteudo').on('submit', '#formInscricao', function (e) {
        e.preventDefault();
        $('#erroInscricao').text('');
        var dados = Object.fromEntries(new FormData(this));
        comTrava($(this).find('button[type=submit]'), chama('inscrever', dados))
            .done(function (res) {
                try { localStorage.setItem('futebol_whatsapp', dados.whatsapp); } catch (err) { /* ignora */ }
                render(res);
            })
            .fail(function (xhr) { $('#erroInscricao').text((xhr.responseJSON || {}).error || 'Não foi possível enviar. Tente de novo.'); });
    });
});
