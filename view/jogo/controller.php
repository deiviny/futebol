<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Arenas;
use Model\Jogos;
use Model\Pagamentos;
use Model\Partidas;
use Model\Presencas;
use Model\Times;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $sess = $_SESSION['sess_usuario'];
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $idJogador = isset($_POST['id_jogador']) ? (int) $_POST['id_jogador'] : 0;
    $idPartida = isset($_POST['id_partida']) ? (int) $_POST['id_partida'] : 0;

    $jogos = new Jogos($sess);

    switch ($acao) {
        case 'carregar':
            $output['arenas'] = (new Arenas($sess))->listing();
            break;

        case 'editar':
            $jogos->atualizar($_POST);
            $output['mensagem'] = 'Jogo atualizado';
            break;

        case 'cancelar':
            $jogos->cancelar($id, isset($_POST['motivo']) ? $_POST['motivo'] : '');
            $output['mensagem'] = 'Marcado como sem jogo';
            break;

        case 'reativar':
            $jogos->reativar($id);
            $output['mensagem'] = 'Jogo reativado';
            break;

        case 'excluir':
            $jogos->excluir($id);
            $output['excluido'] = true;
            break;

        case 'responder':
            (new Presencas($sess))->responder($id, $idJogador, isset($_POST['resposta']) ? $_POST['resposta'] : '');
            break;

        case 'remover_presenca':
            (new Presencas($sess))->remover($id, $idJogador);
            break;

        case 'chegada':
            (new Presencas($sess))->chegada($id, $idJogador, !empty($_POST['chegou']) && $_POST['chegou'] !== '0');
            break;

        case 'todos_chegaram':
            (new Presencas($sess))->todosChegaram($id);
            break;

        case 'sortear':
            (new Times($sess))->sortear($id);
            $output['mensagem'] = 'Times sorteados';
            break;

        case 'time_adicionar':
            $posicao = (new Times($sess))->adicionar($id, $idJogador);
            $output['mensagem'] = 'Entrou na lista de espera (' . $posicao . 'º)';
            break;

        case 'fila_mover':
            (new Times($sess))->moverNaFila($id, $idJogador, isset($_POST['direcao']) ? $_POST['direcao'] : '');
            break;

        case 'partida_iniciar':
            (new Partidas($sess))->iniciar($id);
            $output['mensagem'] = 'Partida iniciada';
            break;

        case 'partida_gol':
            (new Partidas($sess))->gol(
                $id,
                $idPartida,
                $idJogador,
                isset($_POST['lado']) ? $_POST['lado'] : '',
                !empty($_POST['contra']) && $_POST['contra'] !== '0'
            );
            $output['mensagem'] = 'Gol!';
            break;

        case 'partida_gol_desfazer':
            (new Partidas($sess))->desfazerGol($id, isset($_POST['id_gol']) ? (int) $_POST['id_gol'] : 0);
            $output['mensagem'] = 'Gol desfeito';
            break;

        case 'partida_encerrar':
            $res = (new Partidas($sess))->encerrar(
                $id,
                $idPartida,
                isset($_POST['id_time_saiu']) ? (int) $_POST['id_time_saiu'] : 0,
                isset($_POST['lado_par']) ? (string) $_POST['lado_par'] : ''
            );
            $output['resultado'] = $res;
            $d = $res['desempate'];
            if ($d && $d['tipo'] === 'par_impar') {
                $output['mensagem'] = 'Par ou ímpar: ' . $d['num_a'] . ' + ' . $d['num_b'] . ' = ' . $d['soma']
                    . ' (' . ($d['resultado'] === 'par' ? 'par' : 'ímpar') . '). Colete ' . $res['colete_fica'] . ' fica na quadra';
            } else {
                $output['mensagem'] = 'Partida encerrada';
            }
            break;

        case 'partida_reabrir':
            (new Partidas($sess))->reabrir($id, $idPartida);
            $output['mensagem'] = 'Partida reaberta';
            break;

        case 'partida_descartar':
            (new Partidas($sess))->descartar($id, $idPartida);
            $output['mensagem'] = 'Partida descartada';
            break;

        case 'goleiros':
            $jogos->salvarGoleiros($id, $_POST);
            $output['mensagem'] = 'Goleiros salvos';
            break;

        case 'finalizar':
            (new Pagamentos($sess))->finalizar(
                $id,
                isset($_POST['valor_total']) ? $_POST['valor_total'] : '',
                isset($_POST['goleiros_qtd']) ? $_POST : null
            );
            $output['mensagem'] = 'Jogo finalizado. Código Pix gerado';
            break;

        case 'pagar':
            (new Pagamentos($sess))->marcarPago($id, $idJogador, !empty($_POST['pago']) && $_POST['pago'] !== '0');
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Jogo: ação inválida '{$acao}'");
            jsonSaida($output);
            return;
    }

    // Toda ação devolve o jogo atualizado, e a tela se redesenha sem novo pedido.
    if (empty($output['excluido'])) {
        $output['jogo'] = $jogos->detalhe($id);
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Jogo');
}
