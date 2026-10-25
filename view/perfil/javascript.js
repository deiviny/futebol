/* Meus dados: conta do administrador logado */
$(function () {
    api('perfil', 'carregar').done(function (res) {
        var p = res.perfil;
        $('#uNome').val(p.nome);
        $('#uEmail').val(p.email);
        $('#uTelefone').val(p.telefone ? formataTelefone(p.telefone) : '');
        $('#perfilSub').text('Conta criada em ' + p.desde);

        if (!p.grupos.length) {
            $('#perfilGrupos').html('<div class="inset-list"><div class="row-empty">Nenhum grupo ainda.</div></div>');
            return;
        }
        var h = '<div class="inset-list">';
        p.grupos.forEach(function (g) {
            h += '<a class="row-item" href="?tela=grupo&id=' + g.id + '"><div class="row-main"><div class="row-title">' + esc(g.nome) + '</div></div>' +
                '<div class="row-end"><span class="chip' + (g.dono ? ' azul' : '') + '">' + (g.dono ? 'Criou' : 'Admin') + '</span>' + ICONES.seta + '</div></a>';
        });
        $('#perfilGrupos').html(h + '</div>');
    });

    $('#formPerfil').on('submit', function (e) {
        e.preventDefault();
        $('#erroPerfil').text('');
        comTrava($('#btnSalvarPerfil'), api('perfil', 'salvar', Object.fromEntries(new FormData(this)), { silencioso: true }))
            .done(function (res) {
                alertTop('success', res.mensagem);
                $('.nav-user span').text($('#uNome').val());
            })
            .fail(function (xhr) { $('#erroPerfil').text((xhr.responseJSON || {}).error || 'Não foi possível salvar.'); });
    });

    $('#formSenha').on('submit', function (e) {
        e.preventDefault();
        $('#erroSenha').text('');
        var form = this;
        comTrava($('#btnSenha'), api('perfil', 'senha', Object.fromEntries(new FormData(form)), { silencioso: true }))
            .done(function (res) { alertTop('success', res.mensagem); form.reset(); })
            .fail(function (xhr) { $('#erroSenha').text((xhr.responseJSON || {}).error || 'Não foi possível trocar a senha.'); });
    });
});
