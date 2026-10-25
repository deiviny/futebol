<?php
/** Moldura comum a todas as telas (barra de navegação, abas, scripts). */
if (!defined('APP')) {
    exit;
}

$usuario = isset($_SESSION['sess_usuario']) ? $_SESSION['sess_usuario'] : null;
$abas = [
    'inicio' => ['Início', 'casa'],
    'grupos' => ['Grupos', 'grupo'],
    'arenas' => ['Arenas', 'pin'],
];
// "grupo", "jogo" e "relatorio" pertencem à aba Grupos.
$abaAtiva = in_array($tela, ['grupo', 'jogo', 'relatorio'], true) ? 'grupos' : $tela;

$versao = function ($arquivo) {
    $f = __DIR__ . '/../' . $arquivo;

    return is_file($f) ? filemtime($f) : 1;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#F2F2F7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <meta name="csrf" content="<?= e(csrfToken()) ?>">
    <title><?= e($tituloPagina) ?> · Futebol</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E⚽%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/apple.css?v=<?= $versao('assets/css/apple.css') ?>">
</head>
<body class="tela-<?= e($tela) ?> <?= $publica ? 'sem-abas' : '' ?>">

<?php if (!$publica) : ?>
<header class="nav-bar">
    <div class="nav-inner">
        <div class="nav-left">
            <?php if ($voltarUrl) : ?>
                <a class="nav-back" href="<?= e($voltarUrl) ?>"><?= icone('seta-esq') ?><span><?= e($voltarTexto) ?></span></a>
            <?php else : ?>
                <a class="nav-brand" href="?tela=inicio"><?= icone('bola') ?><span>Futebol</span></a>
            <?php endif; ?>
        </div>

        <nav class="nav-tabs-desktop" aria-label="Principal">
            <?php foreach ($abas as $chave => $aba) : ?>
                <a href="?tela=<?= e($chave) ?>" class="<?= $abaAtiva === $chave ? 'ativa' : '' ?>"><?= e($aba[0]) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="nav-right">
            <?= $acoesNav ?>
            <a class="nav-user" href="?tela=perfil" title="Meus dados"><span><?= e($usuario['nome']) ?></span><?= icone('pessoa') ?></a>
            <a class="nav-user" href="?tela=sair" title="Sair"><?= icone('sair') ?></a>
        </div>
    </div>
</header>
<?php endif; ?>

<main class="app-container">
    <?= $conteudo ?>
</main>

<?php if (!$publica) : ?>
<nav class="tab-bar" aria-label="Principal">
    <?php foreach ($abas as $chave => $aba) : ?>
        <a href="?tela=<?= e($chave) ?>" class="<?= $abaAtiva === $chave ? 'ativa' : '' ?>"><?= icone($aba[1]) ?><span><?= e($aba[0]) ?></span></a>
    <?php endforeach; ?>
    <a href="?tela=perfil" class="<?= $abaAtiva === 'perfil' ? 'ativa' : '' ?>"><?= icone('pessoa') ?><span>Meus dados</span></a>
</nav>
<?php endif; ?>

<div id="alertas" aria-live="polite"></div>

<!-- Folha de confirmação (action sheet) -->
<div class="modal fade action-sheet" id="modalConfirmar" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="as-group">
                <div class="as-msg" id="confirmarMensagem"></div>
                <button type="button" class="as-btn" id="confirmarOk"></button>
            </div>
            <div class="as-group"><button type="button" class="as-btn forte" data-dismiss="modal">Cancelar</button></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?= $versao('assets/js/app.js') ?>"></script>
<script src="<?= e($jsView) ?>?v=<?= $versao($jsView) ?>"></script>
</body>
</html>
