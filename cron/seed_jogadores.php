<?php

/**
 * Cadastra jogadores de teste (nomes e WhatsApp fictícios) em um grupo.
 *
 *   php cron/seed_jogadores.php                 # 18 jogadores no grupo mais recente que já tem jogador
 *   php cron/seed_jogadores.php --grupo=3       # em um grupo específico
 *   php cron/seed_jogadores.php --confirmar     # também confirma todos no próximo jogo do grupo
 *
 * Pode rodar mais de uma vez: quem já existe no grupo é pulado.
 * No Docker:  docker compose exec app php cron/seed_jogadores.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente linha de comando.');
}

require_once dirname(__DIR__) . '/model/Init.php';

use Model\Jogadores;
use Model\Jogos;
use Model\Presencas;

$opcoes = getopt('', ['grupo::', 'confirmar']);

// nome, score (0 a 10)
$fake = [
    ['Rafael Souza', 9.0], ['Bruno Lima', 8.5], ['Diego Alves', 8.0], ['Thiago Rocha', 7.5],
    ['Felipe Costa', 7.0], ['Gustavo Reis', 6.5], ['André Nunes', 6.5], ['Lucas Prado', 6.0],
    ['Matheus Dias', 5.5], ['Pedro Cunha', 5.0], ['Caio Barros', 5.0], ['Henrique Faria', 4.5],
    ['Vinícius Melo', 4.5], ['Leonardo Teixeira', 4.0], ['Rodrigo Pires', 4.0], ['Eduardo Campos', 3.5],
    ['Marcelo Duarte', 3.0], ['João Vitor Ramos', 3.0],
];

try {
    $pdo = new PDO(
        'mysql:host=' . SERVER . ';port=' . PORTA . ';dbname=' . BANCO . ';charset=utf8mb4',
        USER,
        PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    if (!empty($opcoes['grupo'])) {
        $stmt = $pdo->prepare("SELECT id, id_usuario, nome FROM grupos WHERE id = ? AND excluido = 'n'");
        $stmt->execute([(int) $opcoes['grupo']]);
    } else {
        $stmt = $pdo->query(
            "SELECT g.id, g.id_usuario, g.nome FROM grupos g
             WHERE g.excluido = 'n'
             ORDER BY (SELECT MAX(gj.id) FROM grupos_jogadores gj WHERE gj.id_grupo = g.id) DESC, g.id DESC LIMIT 1"
        );
    }
    $grupo = $stmt->fetch();
    if (!$grupo) {
        fwrite(STDERR, "Nenhum grupo encontrado. Crie um grupo pelo sistema (e se quiser, 1 jogador) e rode de novo.\n");
        exit(1);
    }

    $dono = ['id' => (int) $grupo['id_usuario']];
    $jogadores = new Jogadores($dono);
    echo "Grupo: {$grupo['nome']} (id {$grupo['id']})\n";

    // "Convidado por": o primeiro jogador que já existe no grupo (se houver).
    $stmt = $pdo->prepare('SELECT id_jogador FROM grupos_jogadores WHERE id_grupo = ? ORDER BY id LIMIT 1');
    $stmt->execute([(int) $grupo['id']]);
    $primeiro = $stmt->fetchColumn() ?: '';

    $criados = 0;
    foreach ($fake as $i => $linha) {
        $numero = '55629800' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT);
        $existe = $jogadores->pessoaPorWhatsapp($numero);
        if ($existe) {
            $stmt = $pdo->prepare('SELECT 1 FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ?');
            $stmt->execute([(int) $grupo['id'], $existe]);
            if ($stmt->fetch()) {
                echo "  já existe: {$linha[0]}\n";
                continue;
            }
        }

        $primeiroNome = explode(' ', $linha[0])[0];
        $instagram = strtolower(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $primeiroNome)));

        $jogadores->salvar([
            'id_grupo' => (int) $grupo['id'],
            'nome' => $linha[0],
            'whatsapp' => $numero,
            'instagram' => $instagram . ($i + 1),
            'data_nascimento' => date('Y-m-d', strtotime('-' . (22 + $i) . ' years -' . ($i * 17) . ' days')),
            'score' => $linha[1],
            'id_convidado_por' => $i % 3 === 0 ? $primeiro : '',
        ]);
        $criados++;
        echo "  + {$linha[0]} (score " . number_format($linha[1], 1, ',', '') . ")\n";
    }
    echo "\n{$criados} jogador(es) cadastrado(s).\n";

    if (isset($opcoes['confirmar'])) {
        $lista = (new Jogos($dono))->listing((int) $grupo['id']);
        $proximo = null;
        foreach ($lista['proximos'] as $j) {
            if ($j['situacao'] === 'agendado') {
                $proximo = $j;
                break;
            }
        }
        if (!$proximo) {
            echo "Sem jogo agendado para confirmar. Crie um jogo (ou ligue o jogo fixo) e rode com --confirmar.\n";
        } else {
            $presencas = new Presencas($dono);
            $n = 0;
            foreach ($jogadores->listing((int) $grupo['id']) as $jog) {
                if ($jog['ativo'] === 's') {
                    $presencas->responder($proximo['id'], $jog['id'], 'confirmado');
                    $n++;
                }
            }
            echo "{$n} jogadores confirmados em {$proximo['data_fmt']}.\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
