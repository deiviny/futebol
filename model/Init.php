<?php

/**
 * Configuração inicial: autoload, .env, constantes do banco, fuso horário.
 *
 * Usa o autoload do Composer quando existe (vendor/). Sem Composer, cai num
 * autoload PSR-4 simples e num leitor de .env mínimo, então o sistema também
 * roda sem "composer install".
 */

$raiz = dirname(__DIR__);

if (is_file($raiz . '/vendor/autoload.php')) {
    require_once $raiz . '/vendor/autoload.php';
} else {
    spl_autoload_register(function ($classe) use ($raiz) {
        $prefixo = 'Model\\';
        if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
            return;
        }
        $arquivo = $raiz . '/model/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
        if (is_file($arquivo)) {
            require_once $arquivo;
        }
    });
}

require_once $raiz . '/ini/funcoes.php';

// Arquivo de configuração: .env na raiz, ou outro caminho em ENV_ARQUIVO (usado no Docker).
$arquivoEnv = getenv('ENV_ARQUIVO') ?: $raiz . '/.env';
define('ARQUIVO_ENV', $arquivoEnv);

if (class_exists('Dotenv\Dotenv')) {
    // createMutable: o arquivo vence variáveis do sistema (ex.: USER do Linux).
    \Dotenv\Dotenv::createMutable(dirname($arquivoEnv), basename($arquivoEnv))->safeLoad();
} elseif (is_file($arquivoEnv)) {
    foreach (file($arquivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || strpos($linha, '=') === false) {
            continue;
        }
        list($chave, $valor) = explode('=', $linha, 2);
        $valor = trim($valor);
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        $_ENV[trim($chave)] = $valor;
    }
}

foreach (['SERVER' => '127.0.0.1', 'PORTA' => '3306', 'USER' => 'root', 'PASS' => '', 'BANCO' => 'futebol'] as $chave => $padrao) {
    if (!defined($chave)) {
        define($chave, isset($_ENV[$chave]) ? $_ENV[$chave] : $padrao);
    }
}

date_default_timezone_set('America/Sao_Paulo');
