<?php

/**
 * Roteador do servidor embutido do PHP (somente desenvolvimento):
 *
 *   php -S localhost:8080 servidor.php
 *
 * Libera só a tela, o router e os arquivos estáticos; bloqueia .env, model/, migrations/ etc.
 * (Em Apache/Nginx o .htaccess cumpre esse papel.)
 */

$caminho = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($caminho === '/' || $caminho === '/index.php' || $caminho === '/router.php') {
    return false;
}

$estatico = preg_match('#^/assets/[A-Za-z0-9_./-]+\.(css|js|svg|png|jpg|jpeg|ico|woff2?)$#i', $caminho)
    || preg_match('#^/view/[a-z]+/javascript\.js$#', $caminho);

if ($estatico && strpos($caminho, '..') === false && is_file(__DIR__ . $caminho)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Não encontrado.';
