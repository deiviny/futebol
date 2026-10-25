<?php

/**
 * Roda as migrations pendentes.
 *
 *   Linha de comando:  php cron/migrate.php
 *   Navegador:         /cron/migrate.php?token=SEU_MIGRATE_TOKEN   (só se MIGRATE_TOKEN estiver no .env)
 */

require_once dirname(__DIR__) . '/model/Init.php';

use Model\Migrate;

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');

    $esperado = (string) env('MIGRATE_TOKEN', '');
    $enviado = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if ($esperado === '' || !hash_equals($esperado, $enviado)) {
        http_response_code(403);
        exit("Acesso negado.\n");
    }
}

try {
    foreach ((new Migrate())->executar() as $linha) {
        echo $linha, "\n";
    }
} catch (Throwable $e) {
    error_log('Erro nas migrations: ' . $e->getMessage());
    if (!$cli) {
        http_response_code(500);
    }
    echo 'ERRO: ', $e->getMessage(), "\n";
    exit(1);
}
