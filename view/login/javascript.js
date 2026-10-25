/* Tela de login / criar conta */
$(function () {
    iniciaSegmentado($('#segLogin'));

    function enviar($form, acao, $erro) {
        $erro.text('');
        var $btn = $form.find('button[type=submit]');
        comTrava($btn, api('login', acao, Object.fromEntries(new FormData($form[0])), { silencioso: true }))
            .done(function () { window.location.href = '?tela=inicio'; })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                $erro.text(res.error || 'Não foi possível entrar. Tente de novo.');
            });
    }

    $('#formEntrar').on('submit', function (e) { e.preventDefault(); enviar($(this), 'entrar', $('#erroEntrar')); });
    $('#formCadastrar').on('submit', function (e) { e.preventDefault(); enviar($(this), 'cadastrar', $('#erroCadastrar')); });
});
