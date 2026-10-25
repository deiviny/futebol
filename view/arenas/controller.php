<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Arenas;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $obj = new Arenas($_SESSION['sess_usuario']);

    switch ($acao) {
        case 'listing':
            $output['lista'] = $obj->listing();
            break;

        case 'salvar':
            $output['id'] = $obj->salvar($_POST);
            $output['mensagem'] = 'Arena salva';
            break;

        case 'excluir':
            $obj->excluir(isset($_POST['id']) ? $_POST['id'] : 0);
            $output['mensagem'] = 'Arena excluída';
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Arenas: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Arenas');
}
