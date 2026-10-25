<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Grupos;
use Model\Jogos;
use Model\Recorrentes;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';

    switch ($acao) {
        case 'carregar':
            // Mantém os jogos fixos das próximas semanas criados (dispensa agendador).
            try {
                (new Recorrentes($_SESSION['sess_usuario']))->gerar(4);
            } catch (\Throwable $e) {
                error_log('Falha ao gerar jogos fixos: ' . $e->getMessage());
            }
            $output['jogos'] = (new Jogos($_SESSION['sess_usuario']))->proximos();
            $output['grupos'] = (new Grupos($_SESSION['sess_usuario']))->listing();
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Inicio: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Inicio');
}
