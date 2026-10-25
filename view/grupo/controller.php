<?php

if (!defined('ROUTER')) {
    http_response_code(403);
    exit;
}

use Model\Arenas;
use Model\Grupos;
use Model\Inscricoes;
use Model\Jogadores;
use Model\Jogos;
use Model\Partidas;
use Model\Recorrentes;

try {
    $output = [];
    require_once $_SESSION['dir'] . '/model/Init.php';

    $acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';
    $sess = $_SESSION['sess_usuario'];
    $idGrupo = isset($_POST['id_grupo']) ? (int) $_POST['id_grupo'] : 0;

    switch ($acao) {
        case 'carregar':
            $output['arenas'] = (new Arenas($sess))->listing();
            if ($idGrupo > 0) {
                $output['grupo'] = (new Grupos($sess))->buscar($idGrupo);
                try {
                    (new Recorrentes($sess))->gerar(4);
                } catch (\Throwable $e) {
                    error_log('Falha ao gerar jogos fixos: ' . $e->getMessage());
                }
                $output['jogos'] = (new Jogos($sess))->listing($idGrupo);
                $output['jogadores'] = (new Jogadores($sess))->listing($idGrupo);
            }
            break;

        case 'salvar':
            $output['id'] = (new Grupos($sess))->salvar($_POST);
            $output['mensagem'] = 'Grupo salvo';
            break;

        case 'excluir':
            (new Grupos($sess))->excluir($idGrupo);
            $output['mensagem'] = 'Grupo excluído';
            break;

        case 'jogos':
            $output['jogos'] = (new Jogos($sess))->listing($idGrupo);
            break;

        case 'jogo_criar':
            $output['id'] = (new Jogos($sess))->criar($_POST);
            $output['mensagem'] = 'Jogo criado';
            break;

        case 'jogo_cancelar':
            (new Jogos($sess))->cancelar(isset($_POST['id']) ? $_POST['id'] : 0, isset($_POST['motivo']) ? $_POST['motivo'] : '');
            $output['mensagem'] = 'Marcado como sem jogo';
            break;

        case 'jogo_reativar':
            (new Jogos($sess))->reativar(isset($_POST['id']) ? $_POST['id'] : 0);
            $output['mensagem'] = 'Jogo reativado';
            break;

        case 'jogadores':
            $output['jogadores'] = (new Jogadores($sess))->listing($idGrupo);
            break;

        case 'ranking':
            $periodo = isset($_POST['periodo']) && in_array($_POST['periodo'], ['tudo', 'ano', '30d'], true) ? $_POST['periodo'] : 'tudo';
            $output['ranking'] = (new Partidas($sess))->ranking($idGrupo, $periodo);
            break;

        case 'jogador_salvar':
            $output['id'] = (new Jogadores($sess))->salvar($_POST);
            $output['mensagem'] = 'Jogador salvo';
            break;

        case 'jogador_remover':
            $resultado = (new Jogadores($sess))->remover($idGrupo, isset($_POST['id']) ? $_POST['id'] : 0);
            $output['mensagem'] = $resultado === 'inativado'
                ? 'Jogador inativado (ele tem histórico nos jogos)'
                : 'Jogador removido do grupo';
            break;

        case 'link_novo':
            $output['link'] = (new Inscricoes($sess))->novoLink($idGrupo);
            $output['mensagem'] = 'Link novo gerado. O anterior parou de funcionar.';
            break;

        case 'inscricao_abrir':
            $aberta = isset($_POST['aberta']) && $_POST['aberta'] === 's';
            (new Inscricoes($sess))->abrir($idGrupo, $aberta);
            $output['mensagem'] = $aberta ? 'Inscrições abertas' : 'Inscrições fechadas';
            break;

        case 'pendente_aprovar':
            (new Inscricoes($sess))->aprovar($idGrupo, isset($_POST['id']) ? $_POST['id'] : 0);
            $output['mensagem'] = 'Jogador aprovado';
            break;

        case 'pendente_recusar':
            (new Inscricoes($sess))->recusar($idGrupo, isset($_POST['id']) ? $_POST['id'] : 0);
            $output['mensagem'] = 'Pedido recusado';
            break;

        default:
            $output['erro'] = "Ação não reconhecida: {$acao}";
            error_log("Controller Grupo: ação inválida '{$acao}'");
    }

    jsonSaida($output);
} catch (\Throwable $e) {
    jsonErro($e, 'Grupo');
}
