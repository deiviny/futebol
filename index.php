<?php

/**
 * Ponto de entrada único. Telas: ?tela=inicio|grupos|grupo|jogo|relatorio|arenas|perfil|login|confirmar|inscricao|sair
 */

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$_SESSION['dir'] = __DIR__;
define('APP', true);

require_once __DIR__ . '/model/Init.php';

$tela = isset($_GET['tela']) ? (string) $_GET['tela'] : 'inicio';
$telasPublicas = ['login', 'confirmar', 'inscricao'];
$telasPrivadas = ['inicio', 'grupos', 'grupo', 'jogo', 'relatorio', 'arenas', 'perfil'];

if ($tela === 'sair') {
    $_SESSION = [];
    session_destroy();
    header('Location: ?tela=login');
    exit;
}

if (!in_array($tela, array_merge($telasPublicas, $telasPrivadas), true)) {
    $tela = 'inicio';
}

$logado = !empty($_SESSION['sess_usuario']['id']);

if (!$logado && in_array($tela, $telasPrivadas, true)) {
    header('Location: ?tela=login');
    exit;
}
if ($logado && $tela === 'login') {
    header('Location: ?tela=inicio');
    exit;
}

// A view roda primeiro (buffer) para poder definir título e botões da barra de navegação.
$tituloPagina = 'Futebol';
$voltarUrl = null;
$voltarTexto = 'Voltar';
$acoesNav = '';

ob_start();
require __DIR__ . '/view/' . $tela . '/index.php';
$conteudo = ob_get_clean();

$publica = in_array($tela, $telasPublicas, true);
$jsView = 'view/' . $tela . '/javascript.js';

require __DIR__ . '/view/layout.php';
