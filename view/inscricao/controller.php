<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Inscricoes;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
    $obj = new Inscricoes();

    switch ($acao) {
        case 'info':
            $output['grupo'] = $obj->publicoInfo($token);
            break;

        case 'inscrever':
            // Freio contra uso abusivo do link público (por sessão): 1 envio a cada 3 segundos.
            if (!empty($_SESSION['inscricao_ultima']) && microtime(true) - $_SESSION['inscricao_ultima'] < 3) {
                throw new \DomainException('Aguarde um instante e tente de novo.');
            }
            $_SESSION['inscricao_ultima'] = microtime(true);

            $output = $obj->publicoInscrever($token, $_POST);
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Inscricao: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Inscricao');
}
