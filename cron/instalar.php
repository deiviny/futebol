<?php

/**
 * Prepara o ambiente: confere o PHP, cria o banco (se não existir) e roda as migrations.
 *
 *   php cron/instalar.php
 *
 * Usa as credenciais do .env (SERVER, PORTA, USER, PASS, BANCO).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente linha de comando.');
}

require_once dirname(__DIR__) . '/model/Init.php';

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, 'ERRO: é preciso PHP 7.4 ou superior (instalado: ' . PHP_VERSION . ").\n");
    exit(1);
}

foreach (['pdo_mysql', 'mbstring', 'json'] as $extensao) {
    if (!extension_loaded($extensao)) {
        fwrite(STDERR, "ERRO: a extensão PHP '{$extensao}' não está ativa. Abra o php.ini e remova o ';' da linha extension={$extensao}.\n");
        exit(1);
    }
}

if (!is_file(ARQUIVO_ENV)) {
    fwrite(STDERR, "ERRO: arquivo .env não encontrado. Copie .env.example para .env e preencha os dados do MySQL.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . SERVER . ';port=' . PORTA . ';charset=utf8mb4',
        USER,
        PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $banco = preg_replace('/[^A-Za-z0-9_]/', '', BANCO);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Banco '{$banco}' pronto.\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'ERRO ao conectar no MySQL: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Confira SERVER, PORTA, USER e PASS no .env e se o MySQL está ligado.\n");
    exit(1);
}

try {
    foreach ((new Model\Migrate())->executar() as $linha) {
        echo $linha, "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO nas migrations: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nTudo pronto. Para abrir o sistema:\n  php -S localhost:8080 servidor.php\nDepois acesse http://localhost:8080\n";
