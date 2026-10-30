<?php

namespace Model;

/**
 * Fechamento do jogo: valor da quadra dividido entre quem jogou, código Pix
 * "copia e cola" e controle de quem já pagou.
 */
class Pagamentos extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /**
     * Informa o valor da quadra, divide (quadra + goleiros contratados) entre os jogadores,
     * gera o Pix e finaliza o jogo. Pode ser repetido para corrigir o valor, enquanto
     * ninguém estiver marcado como pago. $goleiros (opcional) atualiza os goleiros antes.
     *
     * @return array ['valor_por_jogador' => float, 'jogadores' => int, 'codigo_pix' => string]
     */
    public function finalizar($idJogo, $valorTotal, $goleiros = null)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exige($jogo['situacao'] !== 'cancelado', 'Este jogo está cancelado.');

        $total = $this->decimal($valorTotal);
        $this->exige($total !== null && $total > 0 && $total <= 9999999, 'Informe o valor total da quadra.');
        $this->exige(
            !empty($jogo['pix_chave']) && !empty($jogo['pix_nome']) && !empty($jogo['pix_cidade']),
            'Cadastre a chave Pix do grupo (aba Ajustes) antes de finalizar.'
        );

        if ($jogo['situacao'] === 'finalizado') {
            $stmt = $this->_conexao->prepare(
                'SELECT 1 FROM jogos_pagamentos WHERE id_jogo = ? AND pago_em IS NOT NULL LIMIT 1'
            );
            $stmt->execute([(int) $jogo['id']]);
            $this->exige(!$stmt->fetch(), 'Já há pagamentos marcados. Desmarque-os para alterar o valor.');
        }

        $stmt = $this->_conexao->prepare("SELECT 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao = 'em_andamento' LIMIT 1");
        $stmt->execute([(int) $jogo['id']]);
        $this->exige(!$stmt->fetch(), 'Há uma partida em andamento. Encerre ou descarte a partida antes de fechar o jogo.');

        $jogadores = $this->quemJogou((int) $jogo['id']);
        $this->exige($jogadores, 'Ninguém jogou ainda: marque as chegadas e sorteie os times.');

        $this->_conexao->beginTransaction();
        try {
            if (is_array($goleiros)) {
                (new Jogos($this->sess_usuario))->gravaGoleiros((int) $jogo['id'], $goleiros);
                $jogo = $this->jogoDoUsuario($idJogo);
            }
            $totalGoleiros = Jogos::goleiros($jogo)['total'];

            // Divisão em centavos, arredondando para cima para a quadra nunca ficar no prejuízo.
            $centavos = (int) round(($total + $totalGoleiros) * 100);
            $porJogador = (int) ceil($centavos / count($jogadores)) / 100;

            $codigo = PixPayload::gerar($jogo['pix_chave'], $jogo['pix_nome'], $jogo['pix_cidade'], $porJogador);

            $stmt = $this->_conexao->prepare(
                "UPDATE jogos SET situacao = 'finalizado', valor_total = ?, valor_por_jogador = ?,
                        codigo_pix = ?, finalizado_em = ? WHERE id = ?"
            );
            $stmt->execute([$total, $porJogador, $codigo, date('Y-m-d H:i:s'), (int) $jogo['id']]);

            $stmt = $this->_conexao->prepare('DELETE FROM jogos_pagamentos WHERE id_jogo = ?');
            $stmt->execute([(int) $jogo['id']]);

            $stmt = $this->_conexao->prepare('INSERT INTO jogos_pagamentos (id_jogo, id_jogador, valor) VALUES (?, ?, ?)');
            foreach ($jogadores as $idJogador) {
                $stmt->execute([(int) $jogo['id'], (int) $idJogador, $porJogador]);
            }

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return [
            'valor_por_jogador' => $porJogador,
            'jogadores' => count($jogadores),
            'codigo_pix' => $codigo,
        ];
    }

    public function marcarPago($idJogo, $idJogador, $pago)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exige($jogo['situacao'] === 'finalizado', 'O jogo ainda não foi finalizado.');

        $stmt = $this->_conexao->prepare(
            'UPDATE jogos_pagamentos SET pago_em = ? WHERE id_jogo = ? AND id_jogador = ?'
        );
        $stmt->execute([$pago ? date('Y-m-d H:i:s') : null, (int) $jogo['id'], (int) $idJogador]);

        $this->exige($stmt->rowCount() > 0 || $this->existePagamento((int) $jogo['id'], (int) $idJogador), 'Jogador não encontrado neste fechamento.');
    }

    /** Texto para colar no WhatsApp: resumo + código Pix. */
    public static function mensagem(array $jogo)
    {
        $g = Jogos::goleiros($jogo);
        $goleiros = $g['qtd'] ? ' + ' . $g['qtd'] . ($g['qtd'] === 1 ? ' goleiro ' : ' goleiros ') . moeda($g['total']) : '';

        return "⚽ *" . $jogo['grupo_nome'] . "* — " . dataHoraBr($jogo['data_hora']) . "\n"
            . 'Quadra: ' . moeda($jogo['valor_total']) . $goleiros . ' · *' . moeda($jogo['valor_por_jogador']) . " por jogador*\n\n"
            . "Pix copia e cola:\n" . $jogo['codigo_pix'];
    }

    /** Quem jogou: quem está nos times ou na lista de espera ou, sem sorteio, quem chegou. */
    private function quemJogou($idJogo)
    {
        // Quem está em um time, na lista de espera ou já jogou alguma partida.
        $stmt = $this->_conexao->prepare(
            'SELECT tj.id_jogador FROM jogos_times_jogadores tj
             JOIN jogos_times t ON t.id = tj.id_time WHERE t.id_jogo = ?
             UNION
             SELECT id_jogador FROM jogos_fila WHERE id_jogo = ?
             UNION
             SELECT pj.id_jogador FROM jogos_partidas_jogadores pj
             JOIN jogos_partidas p ON p.id = pj.id_partida WHERE p.id_jogo = ?'
        );
        $stmt->execute([$idJogo, $idJogo, $idJogo]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!$ids) {
            $stmt = $this->_conexao->prepare(
                "SELECT id_jogador FROM jogos_presencas
                 WHERE id_jogo = ? AND resposta = 'confirmado' AND chegada_em IS NOT NULL"
            );
            $stmt->execute([$idJogo]);
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return array_map('intval', $ids);
    }

    private function existePagamento($idJogo, $idJogador)
    {
        $stmt = $this->_conexao->prepare('SELECT 1 FROM jogos_pagamentos WHERE id_jogo = ? AND id_jogador = ?');
        $stmt->execute([$idJogo, $idJogador]);

        return (bool) $stmt->fetch();
    }
}
