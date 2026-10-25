<?php

/**
 * Roteador das requisições AJAX (mesmo papel do router.php do SOLUS).
 *
 * O JavaScript chama: POST router.php  { controller: 'view/grupos/controller.php', acao: '...', csrf: '...', ... }
 * O controller recebe $_REQUEST['acao'], responde JSON em $output e usa os models.
 */

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$_SESSION['dir'] = __DIR__;
define('ROUTER', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder($codigo, array $dados)
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['error' => 'Método não permitido.']);
}

// Só controllers conhecidos: nada de caminho vindo do navegador chegando no require.
$controller = isset($_POST['controller']) ? (string) $_POST['controller'] : '';
if (!preg_match('#^view/(login|inicio|arenas|perfil|grupos|grupo|jogo|relatorio|confirmar|inscricao)/controller\.php$#', $controller)
    || !is_file(__DIR__ . '/' . $controller)) {
    responder(404, ['error' => 'Controller não encontrado.']);
}

// Proteção CSRF: o token da sessão precisa voltar junto com a requisição.
$enviado = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
    responder(403, ['error' => 'Sessão inválida. Recarregue a página.', 'recarregar' => true]);
}

// Telas sem login: entrar/criar conta, a confirmação pública de presença e a inscrição no grupo.
$publicos = ['view/login/controller.php', 'view/confirmar/controller.php', 'view/inscricao/controller.php'];
if (!in_array($controller, $publicos, true) && empty($_SESSION['sess_usuario']['id'])) {
    responder(401, ['error' => 'Sessão expirada. Entre novamente.', 'login' => true]);
}

require __DIR__ . '/' . $controller;
