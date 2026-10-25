<?php

/**
 * Cria os jogos fixos das próximas 4 semanas para todos os grupos.
 * Opcional: o sistema já faz isso sozinho quando o administrador abre o app.
 *
 *   php cron/gerar_jogos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente linha de comando.');
}

require_once dirname(__DIR__) . '/model/Init.php';

try {
    $criados = (new Model\Recorrentes())->gerar(4);
    echo "Jogos criados: {$criados}\n";
} catch (Throwable $e) {
    error_log('Erro ao gerar jogos fixos: ' . $e->getMessage());
    echo 'ERRO: ', $e->getMessage(), "\n";
    exit(1);
}
