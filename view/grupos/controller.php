<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Grupos;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $obj = new Grupos($_SESSION['sess_usuario']);

    switch ($acao) {
        case 'listing':
            $output['lista'] = $obj->listing();
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Grupos: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Grupos');
}
