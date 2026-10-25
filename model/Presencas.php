<?php

namespace Model;

/**
 * Presença nos jogos.
 *
 *  - Lado do administrador: confirmar/recusar em nome do jogador, marcar chegada.
 *  - Lado público (sem login): a página do link do jogo, onde cada jogador
 *    confirma pelo próprio WhatsApp. Esses métodos começam com "publico".
 */
class Presencas extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario = null)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    // ---------------------------------------------------------- administrador

    /** Admin confirma ou recusa por um jogador do grupo (inclusive quem apareceu sem confirmar). */
    public function responder($idJogo, $idJogador, $resposta)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige(in_array($resposta, ['confirmado', 'recusado'], true), 'Resposta inválida.');
        $this->jogadorDoGrupo($jogo['id_grupo'], $idJogador);

        $this->gravaResposta((int) $jogo['id'], (int) $idJogador, $resposta);
    }

    /** Tira o jogador da lista do jogo (volta a "pendente"). */
    public function remover($idJogo, $idJogador)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige($jogo['situacao'] === 'agendado', 'Depois do sorteio não dá para tirar jogadores da lista.');

        $stmt = $this->_conexao->prepare('DELETE FROM jogos_presencas WHERE id_jogo = ? AND id_jogador = ?');
        $stmt->execute([(int) $jogo['id'], (int) $idJogador]);
    }

    /** Marca (ou desmarca) a chegada. A hora exata, em milissegundos, define a ordem. */
    public function chegada($idJogo, $idJogador, $chegou)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->jogadorDoGrupo($jogo['id_grupo'], $idJogador);

        if (!$chegou) {
            $stmt = $this->_conexao->prepare(
                'UPDATE jogos_presencas SET chegada_em = NULL WHERE id_jogo = ? AND id_jogador = ?'
            );
            $stmt->execute([(int) $jogo['id'], (int) $idJogador]);

            return;
        }

        // Quem chega sem ter confirmado também entra na lista.
        $this->gravaResposta((int) $jogo['id'], (int) $idJogador, 'confirmado', false);

        $stmt = $this->_conexao->prepare(
            "UPDATE jogos_presencas SET chegada_em = ?
             WHERE id_jogo = ? AND id_jogador = ? AND chegada_em IS NULL"
        );
        $stmt->execute([$this->agora(), (int) $jogo['id'], (int) $idJogador]);
    }

    /** Atalho: todos os confirmados que ainda não chegaram chegam agora, na ordem de confirmação. */
    public function todosChegaram($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);

        $stmt = $this->_conexao->prepare(
            "SELECT id_jogador FROM jogos_presencas
             WHERE id_jogo = ? AND resposta = 'confirmado' AND chegada_em IS NULL
             ORDER BY confirmado_em ASC, id ASC"
        );
        $stmt->execute([(int) $jogo['id']]);

        $upd = $this->_conexao->prepare(
            'UPDATE jogos_presencas SET chegada_em = ? WHERE id_jogo = ? AND id_jogador = ?'
        );
        $base = microtime(true);
        $i = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $idJogador) {
            // 1 ms de diferença entre cada um mantém a ordem estável.
            $upd->execute([$this->agora($base + (++$i) / 1000), (int) $jogo['id'], (int) $idJogador]);
        }
    }

    // ---------------------------------------------------------------- público

    /** Dados exibidos na página pública do link. Sem WhatsApp de ninguém. */
    public function publicoInfo($token)
    {
        $jogo = $this->jogoPorToken($token);

        $stmt = $this->_conexao->prepare(
            "SELECT j.nome FROM jogos_presencas p JOIN jogadores j ON j.id = p.id_jogador
             WHERE p.id_jogo = ? AND p.resposta = 'confirmado' ORDER BY p.confirmado_em ASC, p.id ASC"
        );
        $stmt->execute([(int) $jogo['id']]);

        return [
            'grupo_nome' => $jogo['grupo_nome'],
            'data_fmt' => dataHoraBr($jogo['data_hora']),
            'situacao' => $jogo['situacao'],
            'aberto' => $jogo['situacao'] === 'agendado',
            'motivo_cancelamento' => $jogo['motivo_cancelamento'],
            'arena_nome' => $jogo['arena_nome'],
            'arena_endereco' => $jogo['arena_endereco'],
            'arena_url_mapa' => $jogo['arena_url_mapa'],
            'confirmados' => $stmt->fetchAll(\PDO::FETCH_COLUMN),
        ];
    }

    /**
     * Jogador confirma ou recusa pelo link.
     *
     * @return array ['status' => 'ok'|'precisa_nome', 'nome' => ..., 'resposta' => ...]
     */
    public function publicoResponder($token, $whatsapp, $nome, $resposta)
    {
        $jogo = $this->jogoPorToken($token);
        $this->exige($jogo['situacao'] === 'agendado', 'As confirmações deste jogo estão encerradas.');
        $this->exige(in_array($resposta, ['confirmado', 'recusado'], true), 'Resposta inválida.');

        $numero = Jogadores::normalizaWhatsapp($whatsapp);
        $this->exige($numero !== null, 'WhatsApp inválido. Use DDD + número (ex.: 62 99999-8888).');

        $idGrupo = (int) $jogo['id_grupo'];
        $nome = trim((string) $nome);

        $stmt = $this->_conexao->prepare(
            "SELECT j.id, j.nome, gj.ativo FROM jogadores j
             JOIN grupos_jogadores gj ON gj.id_jogador = j.id AND gj.id_grupo = ?
             WHERE j.whatsapp = ?"
        );
        $stmt->execute([$idGrupo, $numero]);
        $membro = $stmt->fetch();

        if ($membro) {
            $this->exige($membro['ativo'] === 's', 'Seu cadastro está inativo neste grupo. Fale com o administrador.');
            $this->gravaResposta((int) $jogo['id'], (int) $membro['id'], $resposta);

            return ['status' => 'ok', 'nome' => $membro['nome'], 'resposta' => $resposta];
        }

        // Número novo no grupo: pede o nome e cadastra com score padrão (o admin ajusta depois).
        if ($nome === '') {
            return ['status' => 'precisa_nome'];
        }
        $this->exige(mb_strlen($nome) <= 120, 'Nome muito longo.');

        $this->_conexao->beginTransaction();
        try {
            $stmt = $this->_conexao->prepare('SELECT id FROM jogadores WHERE whatsapp = ?');
            $stmt->execute([$numero]);
            $existente = $stmt->fetch();

            if ($existente) {
                $idJogador = (int) $existente['id'];
            } else {
                $stmt = $this->_conexao->prepare('INSERT INTO jogadores (nome, whatsapp) VALUES (?, ?)');
                $stmt->execute([$nome, $numero]);
                $idJogador = (int) $this->_conexao->lastInsertId();
            }

            $stmt = $this->_conexao->prepare('INSERT IGNORE INTO grupos_jogadores (id_grupo, id_jogador) VALUES (?, ?)');
            $stmt->execute([$idGrupo, $idJogador]);

            $this->gravaResposta((int) $jogo['id'], $idJogador, $resposta);
            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return ['status' => 'ok', 'nome' => $nome, 'resposta' => $resposta];
    }

    // ------------------------------------------------------------ auxiliares

    private function jogoPorToken($token)
    {
        $this->exige(preg_match('/^[0-9a-f]{32}$/', (string) $token), 'Link inválido.');

        $stmt = $this->_conexao->prepare(
            "SELECT jo.*, g.nome AS grupo_nome, a.nome AS arena_nome, a.endereco AS arena_endereco, a.url_mapa AS arena_url_mapa
             FROM jogos jo
             JOIN grupos g ON g.id = jo.id_grupo AND g.excluido = 'n'
             LEFT JOIN arenas a ON a.id = jo.id_arena
             WHERE jo.token = ?"
        );
        $stmt->execute([$token]);
        $jogo = $stmt->fetch();

        $this->exige($jogo, 'Link inválido ou jogo não encontrado.');

        return $jogo;
    }

    /** Cria ou atualiza a resposta. Mudar a resposta mantém a hora original da confirmação. */
    private function gravaResposta($idJogo, $idJogador, $resposta, $limpaChegadaSeRecusou = true)
    {
        $stmt = $this->_conexao->prepare(
            'INSERT INTO jogos_presencas (id_jogo, id_jogador, resposta, confirmado_em)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                confirmado_em = IF(resposta = VALUES(resposta), confirmado_em, VALUES(confirmado_em)),
                resposta = VALUES(resposta)'
        );
        $stmt->execute([$idJogo, $idJogador, $resposta, date('Y-m-d H:i:s')]);

        if ($resposta === 'recusado' && $limpaChegadaSeRecusou) {
            $stmt = $this->_conexao->prepare(
                'UPDATE jogos_presencas SET chegada_em = NULL WHERE id_jogo = ? AND id_jogador = ?'
            );
            $stmt->execute([$idJogo, $idJogador]);
        }
    }

    /** Data e hora atuais com milissegundos ("2026-10-24 20:01:02.345"). */
    private function agora($timestamp = null)
    {
        $t = $timestamp === null ? microtime(true) : $timestamp;
        $ms = (int) round(($t - floor($t)) * 1000);
        if ($ms > 999) {
            $ms = 999;
        }

        return date('Y-m-d H:i:s', (int) floor($t)) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT);
    }
}
