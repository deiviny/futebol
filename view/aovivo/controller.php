<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\AoVivo;

/** Grava (ou apaga, com $valor vazio) o cookie do login do jogador. */
function cookieJogador($valor)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie(AoVivo::COOKIE, $valor, [
        'expires' => $valor === '' ? time() - 3600 : time() + AoVivo::DIAS * 86400,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
    $cookie = isset($_COOKIE[AoVivo::COOKIE]) ? (string) $_COOKIE[AoVivo::COOKIE] : '';
    $obj = new AoVivo(isset($_SESSION['sess_usuario']) ? $_SESSION['sess_usuario'] : null);

    $jogador = $obj->jogadorDoToken($cookie);

    // Sem login (ou login vencido): a tela mostra o campo do telefone.
    $exigeLogin = function () use ($jogador, $cookie) {
        if (!$jogador) {
            if ($cookie !== '') {
                cookieJogador('');
            }
            throw new \DomainException('Entre com o seu telefone.', 401);
        }
    };

    switch ($acao) {
        case 'info':
            if (!$jogador) {
                $output['logado'] = false;
                $output['jogo'] = $obj->cabecalho($token);
                break;
            }
            $output['logado'] = true;
            $output['jogo'] = $obj->tela($token, $jogador);
            break;

        case 'entrar':
            // Freio contra tentativas em série (por sessão): 1 a cada 2 segundos e no máximo 15 por hora.
            $agora = microtime(true);
            $tentativas = isset($_SESSION['aovivo_tentativas']) ? array_filter((array) $_SESSION['aovivo_tentativas'], function ($t) use ($agora) {
                return $agora - $t < 3600;
            }) : [];
            if ($tentativas && $agora - max($tentativas) < 2) {
                throw new \DomainException('Aguarde um instante e tente de novo.');
            }
            if (count($tentativas) >= 15) {
                throw new \DomainException('Muitas tentativas. Espere um pouco e tente de novo.');
            }
            $tentativas[] = $agora;
            $_SESSION['aovivo_tentativas'] = array_values($tentativas);

            $res = $obj->entrar($token, isset($_POST['telefone']) ? $_POST['telefone'] : '');
            cookieJogador($res['token']);
            $output['logado'] = true;
            $output['jogo'] = $obj->tela($token, $res['jogador']);
            break;

        case 'sair':
            $obj->sair($cookie);
            cookieJogador('');
            $output['logado'] = false;
            $output['jogo'] = $obj->cabecalho($token);
            break;

        case 'gol':
            $exigeLogin();
            $obj->gol($token, $jogador, $_POST);
            $output['mensagem'] = 'Gol marcado!';
            $output['logado'] = true;
            $output['jogo'] = $obj->tela($token, $jogador);
            break;

        case 'gol_desfazer':
            $exigeLogin();
            $obj->desfazerGol($token, $jogador, isset($_POST['id_gol']) ? (int) $_POST['id_gol'] : 0);
            $output['mensagem'] = 'Gol desfeito';
            $output['logado'] = true;
            $output['jogo'] = $obj->tela($token, $jogador);
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller AoVivo: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    if ($e instanceof \DomainException && $e->getCode() === 401) {
        http_response_code(401);
        echo json_encode(['error' => $e->getMessage(), 'entrar' => true], JSON_UNESCAPED_UNICODE);

        return;
    }
    jsonErro($e, 'AoVivo');
}
