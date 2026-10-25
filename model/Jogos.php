<?php

namespace Model;

/**
 * Jogos de um grupo: agenda, detalhe completo (presenças, times, pagamentos),
 * cancelamento ("sem jogo neste dia") e link público de confirmação.
 */
class Jogos extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /** Jogos do grupo: próximos (abertos ou cancelados) e anteriores. */
    public function listing($idGrupo)
    {
        $this->grupoDoUsuario($idGrupo);

        $stmt = $this->_conexao->prepare(
            "SELECT jo.id, jo.data_hora, jo.situacao, jo.motivo_cancelamento, a.nome AS arena_nome,
                    (SELECT COUNT(*) FROM jogos_presencas p WHERE p.id_jogo = jo.id AND p.resposta = 'confirmado') AS confirmados
             FROM jogos jo
             LEFT JOIN arenas a ON a.id = jo.id_arena
             WHERE jo.id_grupo = ?
             ORDER BY jo.data_hora DESC
             LIMIT 200"
        );
        $stmt->execute([(int) $idGrupo]);

        $hoje = date('Y-m-d 00:00:00');
        $proximos = [];
        $anteriores = [];

        foreach ($stmt->fetchAll() as $jogo) {
            $item = $this->resumo($jogo);
            $aberto = in_array($jogo['situacao'], ['agendado', 'em_andamento'], true);

            if ($jogo['data_hora'] >= $hoje && $jogo['situacao'] !== 'finalizado') {
                $proximos[] = $item;
            } elseif ($aberto) {
                $proximos[] = $item; // jogo passado que ninguém encerrou continua pendente
            } else {
                $anteriores[] = $item;
            }
        }

        usort($proximos, function ($a, $b) {
            return strcmp($a['data_hora'], $b['data_hora']);
        });

        return ['proximos' => $proximos, 'anteriores' => array_slice($anteriores, 0, 30)];
    }

    /** Próximos jogos de todos os grupos do usuário (tela inicial). */
    public function proximos()
    {
        $stmt = $this->_conexao->prepare(
            "SELECT jo.id, jo.id_grupo, jo.data_hora, jo.situacao, jo.motivo_cancelamento,
                    g.nome AS grupo_nome, a.nome AS arena_nome,
                    (SELECT COUNT(*) FROM jogos_presencas p WHERE p.id_jogo = jo.id AND p.resposta = 'confirmado') AS confirmados
             FROM jogos jo
             JOIN grupos g ON g.id = jo.id_grupo AND g.id_usuario = ? AND g.excluido = 'n'
             LEFT JOIN arenas a ON a.id = jo.id_arena
             WHERE (jo.situacao = 'em_andamento'
                    OR (jo.situacao IN ('agendado','cancelado') AND jo.data_hora >= ? AND jo.data_hora < ?))
             ORDER BY jo.data_hora ASC
             LIMIT 30"
        );
        $stmt->execute([$this->idUsuario(), date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+15 days'))]);

        $lista = [];
        foreach ($stmt->fetchAll() as $jogo) {
            $item = $this->resumo($jogo);
            $item['id_grupo'] = (int) $jogo['id_grupo'];
            $item['grupo_nome'] = $jogo['grupo_nome'];
            $lista[] = $item;
        }

        return $lista;
    }

    /** Tudo sobre o jogo para a tela de detalhe. */
    public function detalhe($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $id = (int) $jogo['id'];
        $idGrupo = (int) $jogo['id_grupo'];

        $presencas = $this->consulta(
            'SELECT p.id_jogador, p.resposta, p.confirmado_em, p.chegada_em, j.nome, j.whatsapp, gj.score
             FROM jogos_presencas p
             JOIN jogadores j ON j.id = p.id_jogador
             LEFT JOIN grupos_jogadores gj ON gj.id_grupo = ? AND gj.id_jogador = p.id_jogador
             WHERE p.id_jogo = ?
             ORDER BY (p.chegada_em IS NULL), p.chegada_em ASC, p.confirmado_em ASC, j.nome ASC',
            [$idGrupo, $id]
        );

        $confirmados = [];
        $recusados = [];
        $ordem = 0;
        foreach ($presencas as $p) {
            $linha = [
                'id_jogador' => (int) $p['id_jogador'],
                'nome' => $p['nome'],
                'whatsapp' => $p['whatsapp'],
                'score' => (float) $p['score'],
                'chegou' => $p['chegada_em'] !== null,
                'ordem_chegada' => null,
            ];
            if ($p['resposta'] === 'confirmado') {
                if ($p['chegada_em'] !== null) {
                    $linha['ordem_chegada'] = ++$ordem;
                }
                $confirmados[] = $linha;
            } else {
                $recusados[] = $linha;
            }
        }

        $pendentes = $this->consulta(
            "SELECT j.id AS id_jogador, j.nome, j.whatsapp, gj.score
             FROM grupos_jogadores gj
             JOIN jogadores j ON j.id = gj.id_jogador
             WHERE gj.id_grupo = ? AND gj.ativo = 's'
               AND NOT EXISTS (SELECT 1 FROM jogos_presencas p WHERE p.id_jogo = ? AND p.id_jogador = j.id)
             ORDER BY j.nome ASC",
            [$idGrupo, $id]
        );
        foreach ($pendentes as &$p) {
            $p['id_jogador'] = (int) $p['id_jogador'];
            $p['score'] = (float) $p['score'];
        }

        $times = $this->times($id, $jogo);
        $painel = (new Partidas($this->sess_usuario))->painel($jogo);

        // Chegaram (e estão na lista) mas ainda não entraram em time nem na lista de espera.
        $escalados = [];
        foreach ($times as $t) {
            foreach ($t['jogadores'] as $jg) {
                $escalados[$jg['id_jogador']] = true;
            }
        }
        foreach ($painel['fila'] as $jg) {
            $escalados[$jg['id_jogador']] = true;
        }
        $sorteado = $this->foiSorteado($id);
        $semTime = [];
        if ($sorteado) {
            foreach ($confirmados as $c) {
                if ($c['chegou'] && !isset($escalados[$c['id_jogador']])) {
                    $semTime[] = ['id_jogador' => $c['id_jogador'], 'nome' => $c['nome'], 'score' => $c['score']];
                }
            }
        }

        $diferenca = null;
        if (count($times) >= 2 && !$painel['tem_partidas']) {
            // Antes da primeira partida só existem os dois times sorteados.
            $somas = array_map(function ($t) {
                return $t['soma'];
            }, array_slice($times, 0, 2));
            $diferenca = round(max($somas) - min($somas), 1);
        }

        return array_merge($painel, [
            'id' => $id,
            'id_grupo' => $idGrupo,
            'grupo_nome' => $jogo['grupo_nome'],
            'data_hora' => $jogo['data_hora'],
            'data' => substr($jogo['data_hora'], 0, 10),
            'hora' => substr($jogo['data_hora'], 11, 5),
            'data_fmt' => dataHoraBr($jogo['data_hora']),
            'situacao' => $jogo['situacao'],
            'aberto' => in_array($jogo['situacao'], ['agendado', 'em_andamento'], true),
            'motivo_cancelamento' => $jogo['motivo_cancelamento'],
            'jogadores_por_time' => (int) $jogo['jogadores_por_time'],
            'id_arena' => $jogo['id_arena'] ? (int) $jogo['id_arena'] : null,
            'arena' => $jogo['id_arena'] ? [
                'nome' => $jogo['arena_nome'],
                'endereco' => $jogo['arena_endereco'],
                'url_mapa' => $jogo['arena_url_mapa'],
                'valor' => (float) $jogo['arena_valor'],
            ] : null,
            'link_publico' => baseUrl() . '/?tela=confirmar&t=' . $jogo['token'],
            'confirmados' => $confirmados,
            'recusados' => $recusados,
            'pendentes' => $pendentes,
            'chegaram' => $ordem,
            'times' => $times,
            'sorteado' => $sorteado,
            'sem_time' => $semTime,
            'diferenca' => $diferenca,
            'pix_configurado' => !empty($jogo['pix_chave']),
            'fechamento' => $this->fechamento($jogo),
        ]);
    }

    /** @return int id do jogo criado */
    public function criar(array $dados)
    {
        $idGrupo = isset($dados['id_grupo']) ? (int) $dados['id_grupo'] : 0;
        $grupo = $this->grupoDoUsuario($idGrupo);

        $dataHora = $this->dataHora($dados);
        $idArena = $this->arenaValida($dados, $grupo['id_arena_padrao']);
        $porTime = $this->jogadoresPorTime($dados, $grupo['jogadores_por_time']);

        $this->exige(!$this->jogoNoDia($idGrupo, $dataHora, 0), 'Já existe um jogo deste grupo neste dia.');

        $stmt = $this->_conexao->prepare(
            'INSERT INTO jogos (id_grupo, id_arena, data_hora, jogadores_por_time, token) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$idGrupo, $idArena, $dataHora, $porTime, bin2hex(random_bytes(16))]);

        return (int) $this->_conexao->lastInsertId();
    }

    public function atualizar(array $dados)
    {
        $jogo = $this->jogoDoUsuario(isset($dados['id']) ? $dados['id'] : 0);
        $this->exigeJogoAberto($jogo);

        $dataHora = $this->dataHora($dados);
        $idArena = $this->arenaValida($dados, null);
        $porTime = $this->jogadoresPorTime($dados, $jogo['jogadores_por_time']);

        $this->exige(!$this->jogoNoDia((int) $jogo['id_grupo'], $dataHora, (int) $jogo['id']), 'Já existe um jogo deste grupo neste dia.');

        $stmt = $this->_conexao->prepare(
            'UPDATE jogos SET data_hora = ?, id_arena = ?, jogadores_por_time = ? WHERE id = ?'
        );
        $stmt->execute([$dataHora, $idArena, $porTime, (int) $jogo['id']]);
    }

    /** "Sem jogo neste dia". */
    public function cancelar($idJogo, $motivo = '')
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exige($jogo['situacao'] === 'agendado', 'Só é possível marcar "sem jogo" em jogos agendados.');

        $motivo = mb_substr(trim((string) $motivo), 0, 200);

        $stmt = $this->_conexao->prepare(
            "UPDATE jogos SET situacao = 'cancelado', motivo_cancelamento = ? WHERE id = ?"
        );
        $stmt->execute([$motivo !== '' ? $motivo : null, (int) $jogo['id']]);
    }

    public function reativar($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exige($jogo['situacao'] === 'cancelado', 'Este jogo não está cancelado.');

        $stmt = $this->_conexao->prepare(
            "UPDATE jogos SET situacao = 'agendado', motivo_cancelamento = NULL WHERE id = ?"
        );
        $stmt->execute([(int) $jogo['id']]);
    }

    /** Exclui um jogo ainda sem histórico (agendado/cancelado, sem times nem pagamentos). */
    public function excluir($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exige(in_array($jogo['situacao'], ['agendado', 'cancelado'], true), 'Jogos em andamento ou finalizados não podem ser excluídos.');

        // Jogo fixo: apagar a ocorrência faria o gerador recriá-la. Nesse caso o certo é "sem jogo".
        $grupo = $this->grupoDoUsuario($jogo['id_grupo']);
        $segueOFixo = $grupo['recorrente'] === 's'
            && (int) date('w', strtotime($jogo['data_hora'])) === (int) $grupo['dia_semana']
            && substr($jogo['data_hora'], 11, 5) === substr((string) $grupo['horario'], 0, 5);
        $this->exige(!$segueOFixo, 'Este jogo é do horário fixo do grupo. Use "Sem jogo neste dia".');

        $stmt = $this->_conexao->prepare('DELETE FROM jogos WHERE id = ?');
        $stmt->execute([(int) $jogo['id']]);
    }

    // ---------------------------------------------------------------- auxiliares

    private function resumo(array $jogo)
    {
        return [
            'id' => (int) $jogo['id'],
            'data_hora' => $jogo['data_hora'],
            'data_fmt' => dataHoraBr($jogo['data_hora']),
            'situacao' => $jogo['situacao'],
            'motivo_cancelamento' => $jogo['motivo_cancelamento'],
            'arena_nome' => $jogo['arena_nome'],
            'confirmados' => (int) $jogo['confirmados'],
        ];
    }

    private function consulta($sql, array $params)
    {
        $stmt = $this->_conexao->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Times que estão na quadra (com colete), com os jogadores na ordem da lista.
     * Quem espera está na lista de espera (Partidas::painel → fila).
     */
    private function times($idJogo, array $jogo)
    {
        $times = $this->consulta(
            'SELECT id, nome, cor, posicao, colete FROM jogos_times
             WHERE id_jogo = ? AND colete IS NOT NULL ORDER BY colete ASC, id ASC',
            [(int) $idJogo]
        );

        foreach ($times as &$time) {
            $time['id'] = (int) $time['id'];
            $time['colete'] = (int) $time['colete'];
            // A cor segue o cadastro do grupo, mesmo se ele mudou depois do sorteio.
            $time['cor'] = $jogo['colete' . $time['colete'] . '_cor'];
            $time['colete_nome'] = $jogo['colete' . $time['colete'] . '_nome'];
            $time['jogadores'] = $this->consulta(
                'SELECT tj.id_jogador, j.nome, tj.score
                 FROM jogos_times_jogadores tj JOIN jogadores j ON j.id = tj.id_jogador
                 WHERE tj.id_time = ? ORDER BY tj.ordem ASC, j.nome ASC',
                [$time['id']]
            );

            $soma = 0.0;
            foreach ($time['jogadores'] as &$jogador) {
                $jogador['id_jogador'] = (int) $jogador['id_jogador'];
                $jogador['score'] = (float) $jogador['score'];
                $soma += $jogador['score'];
            }
            unset($jogador);
            $time['soma'] = round($soma, 1);
        }
        unset($time);

        return array_values(array_filter($times, function ($t) {
            return count($t['jogadores']) > 0;
        }));
    }

    private function foiSorteado($idJogo)
    {
        return (bool) $this->consulta('SELECT 1 FROM jogos_times WHERE id_jogo = ? LIMIT 1', [(int) $idJogo]);
    }

    /** Fechamento: valor, código Pix e quem já pagou. null enquanto não finalizado. */
    private function fechamento(array $jogo)
    {
        if ($jogo['situacao'] !== 'finalizado') {
            return null;
        }

        $pagamentos = $this->consulta(
            'SELECT pg.id_jogador, j.nome, j.whatsapp, pg.valor, pg.pago_em
             FROM jogos_pagamentos pg JOIN jogadores j ON j.id = pg.id_jogador
             WHERE pg.id_jogo = ? ORDER BY (pg.pago_em IS NOT NULL), j.nome ASC',
            [(int) $jogo['id']]
        );

        $pagos = 0;
        foreach ($pagamentos as &$p) {
            $p['id_jogador'] = (int) $p['id_jogador'];
            $p['valor'] = (float) $p['valor'];
            $p['pago'] = $p['pago_em'] !== null;
            $pagos += $p['pago'] ? 1 : 0;
        }

        return [
            'valor_total' => (float) $jogo['valor_total'],
            'valor_por_jogador' => (float) $jogo['valor_por_jogador'],
            'codigo_pix' => $jogo['codigo_pix'],
            'mensagem_whatsapp' => Pagamentos::mensagem($jogo),
            'finalizado_em' => $jogo['finalizado_em'],
            'pagamentos' => $pagamentos,
            'pagos' => $pagos,
            'total' => count($pagamentos),
        ];
    }

    /** "data" (AAAA-MM-DD) + "hora" (HH:MM) => "AAAA-MM-DD HH:MM:00" */
    private function dataHora(array $dados)
    {
        $data = isset($dados['data']) ? trim($dados['data']) : '';
        $hora = isset($dados['hora']) ? trim($dados['hora']) : '';

        $dt = \DateTime::createFromFormat('Y-m-d H:i', $data . ' ' . $hora);
        $this->exige($dt && $dt->format('Y-m-d H:i') === $data . ' ' . $hora, 'Informe a data e o horário do jogo.');

        return $dt->format('Y-m-d H:i:00');
    }

    private function arenaValida(array $dados, $padrao)
    {
        $id = isset($dados['id_arena']) && $dados['id_arena'] !== '' ? (int) $dados['id_arena'] : (int) $padrao;
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->_conexao->prepare("SELECT id FROM arenas WHERE id = ? AND excluido = 'n'");
        $stmt->execute([$id]);
        $this->exige($stmt->fetch(), 'Arena não encontrada.');

        return $id;
    }

    private function jogadoresPorTime(array $dados, $padrao)
    {
        $n = isset($dados['jogadores_por_time']) && $dados['jogadores_por_time'] !== '' ? (int) $dados['jogadores_por_time'] : (int) $padrao;
        $this->exige($n >= 2 && $n <= 11, 'Jogadores por time: de 2 a 11.');

        return $n;
    }

    /** Já existe jogo do grupo nesse dia (ignorando $ignorarId)? */
    private function jogoNoDia($idGrupo, $dataHora, $ignorarId)
    {
        $dia = substr($dataHora, 0, 10);
        $stmt = $this->_conexao->prepare(
            'SELECT 1 FROM jogos WHERE id_grupo = ? AND data_hora >= ? AND data_hora < ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([(int) $idGrupo, $dia . ' 00:00:00', date('Y-m-d', strtotime($dia . ' +1 day')) . ' 00:00:00', (int) $ignorarId]);

        return (bool) $stmt->fetch();
    }
}
