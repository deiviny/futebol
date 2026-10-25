<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Login;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $obj = new Login();

    // Freio simples contra tentativa de senha em sequência (por sessão).
    if (!empty($_SESSION['login_bloqueado_ate']) && $_SESSION['login_bloqueado_ate'] > time()) {
        throw new \DomainException('Muitas tentativas. Aguarde um minuto e tente de novo.');
    }

    switch ($acao) {
        case 'entrar':
            try {
                $usuario = $obj->autenticar($_POST);
            } catch (\DomainException $e) {
                $_SESSION['login_falhas'] = (isset($_SESSION['login_falhas']) ? $_SESSION['login_falhas'] : 0) + 1;
                if ($_SESSION['login_falhas'] >= 5) {
                    $_SESSION['login_bloqueado_ate'] = time() + 60;
                    $_SESSION['login_falhas'] = 0;
                }
                usleep(400000);
                throw $e;
            }
            session_regenerate_id(true);
            $_SESSION['sess_usuario'] = $usuario;
            unset($_SESSION['login_falhas']);
            $output['ok'] = true;
            break;

        case 'cadastrar':
            $usuario = $obj->cadastrar($_POST);
            session_regenerate_id(true);
            $_SESSION['sess_usuario'] = $usuario;
            $output['ok'] = true;
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Login: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Login');
}
