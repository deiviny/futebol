<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Partidas;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $sess = $_SESSION['sess_usuario'];
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    switch ($acao) {
        case 'carregar':
            $output['relatorio'] = (new Partidas($sess))->relatorioDoJogo($id);
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Relatorio: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Relatorio');
}
