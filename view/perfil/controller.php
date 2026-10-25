<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Perfil;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $obj = new Perfil($_SESSION['sess_usuario']);

    switch ($acao) {
        case 'carregar':
            $output['perfil'] = $obj->dados();
            break;

        case 'salvar':
            $_SESSION['sess_usuario'] = $obj->salvar($_POST);
            $output['mensagem'] = 'Dados salvos';
            break;

        case 'senha':
            $obj->trocarSenha($_POST);
            $output['mensagem'] = 'Senha alterada';
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Perfil: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Perfil');
}
