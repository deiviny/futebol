<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Presencas;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
    $obj = new Presencas();

    switch ($acao) {
        case 'info':
            $output['jogo'] = $obj->publicoInfo($token);
            break;

        case 'responder':
            // Freio contra uso abusivo do link público (por sessão): 1 resposta a cada 2 segundos.
            if (!empty($_SESSION['confirmar_ultima']) && microtime(true) - $_SESSION['confirmar_ultima'] < 2) {
                throw new \DomainException('Aguarde um instante e tente de novo.');
            }
            $_SESSION['confirmar_ultima'] = microtime(true);

            $output = $obj->publicoResponder(
                $token,
                isset($_POST['whatsapp']) ? $_POST['whatsapp'] : '',
                isset($_POST['nome']) ? $_POST['nome'] : '',
                isset($_POST['resposta']) ? $_POST['resposta'] : ''
            );
            $output['jogo'] = $obj->publicoInfo($token);
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Confirmar: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Confirmar');
}
