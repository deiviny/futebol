<?php

namespace Model;

/**
 * Partidas da noite: início, gols, fim, rotação dos times, histórico e relatório.
 *
 * Regras da rotação (pelada com dois coletes e uma LISTA de espera):
 *  - O lado "a" veste o colete 1 e o lado "b" o colete 2.
 *  - Quem ganha fica na quadra, com o mesmo colete. Quem perde sai e seus
 *    jogadores vão para o FIM da lista, na ordem em que estavam.
 *  - Empate: par ou ímpar (o app sorteia os números; quem ganha fica) ou escolha manual.
 *  - O time que entra é formado pelos primeiros da lista, do primeiro para o último:
 *    quem estava de fora vem antes de quem acabou de sair. Se a lista não tiver gente
 *    suficiente, o time se completa com os primeiros de quem acabou de sair.
 *  - O time que entra veste o colete de quem saiu.
 *  - O sorteio por score vale só para a primeira formação (ver Times::sortear).
 *  - Tudo fica gravado: quem jogou de cada lado, de onde cada um veio, os gols, o tempo
 *    e como o empate foi decidido. O relatório do jogo sai desses registros.
 */
class Partidas extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    // ================================================================ ações

    /**
     * Inicia a próxima partida. Depois da primeira, o time que entra vem da lista de espera.
     *
     * @return int id da partida
     */
    public function iniciar($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige($jogo['situacao'] === 'em_andamento', 'Sorteie os times antes de iniciar uma partida.');

        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Já existe uma partida em andamento.');

            $plano = $this->plano($jogo);
            $this->exige($plano, 'Não foi possível montar a próxima partida.');
            $this->exige(empty($plano['erro']), isset($plano['erro']) ? $plano['erro'] : '');

            $entrou = null;
            $origemId = null;
            $origemNome = null;
            $vieramDoSaiu = [];

            if ($plano['primeira']) {
                $idA = (int) $plano['lado_a']['id'];
                $idB = (int) $plano['lado_b']['id'];
                $this->defineColete($idA, 1, $jogo);
                $this->defineColete($idB, 2, $jogo);
            } else {
                $entra = $plano['entra'];
                $numeroColete = (int) $plano['colete_entra'];
                $entrou = $this->formaTimeQueEntra($jogo, $entra, $numeroColete);
                $origemId = (int) $plano['saiu']['id'];
                $origemNome = $plano['saiu']['nome'];
                foreach ($entra['jogadores'] as $jg) {
                    if ($jg['veio_do_saiu']) {
                        $vieramDoSaiu[(int) $jg['id_jogador']] = true;
                    }
                }

                $idFica = (int) $plano['fica']['id'];
                $idA = $numeroColete === 1 ? $entrou : $idFica;
                $idB = $numeroColete === 1 ? $idFica : $entrou;
            }

            $elenco = $this->elenco($id);
            $a = $elenco[$idA];
            $b = $elenco[$idB];

            $stmt = $this->_conexao->prepare(
                "SELECT COALESCE(MAX(numero), 0) + 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao <> 'descartada'"
            );
            $stmt->execute([$id]);
            $numero = (int) $stmt->fetchColumn();

            $stmt = $this->_conexao->prepare(
                'INSERT INTO jogos_partidas
                    (id_jogo, numero, id_time_a, id_time_b, nome_a, nome_b, colete_a, colete_b,
                     colete_nome_a, colete_nome_b, cor_a, cor_b, iniciada_em, id_time_entrou)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 2, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $id, $numero, (int) $a['id'], (int) $b['id'], $a['nome'], $b['nome'],
                $jogo['colete1_nome'], $jogo['colete2_nome'], $jogo['colete1_cor'], $jogo['colete2_cor'],
                date('Y-m-d H:i:s'), $entrou,
            ]);
            $idPartida = (int) $this->_conexao->lastInsertId();

            $stmt = $this->_conexao->prepare(
                'INSERT INTO jogos_partidas_jogadores (id_partida, id_jogador, lado, emprestado, id_time_origem, nome_time_origem)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ([['a', $a], ['b', $b]] as $par) {
                foreach ($par[1]['jogadores'] as $jg) {
                    $veio = isset($vieramDoSaiu[(int) $jg['id_jogador']]);
                    $stmt->execute([
                        $idPartida, (int) $jg['id_jogador'], $par[0], $veio ? 's' : 'n',
                        $veio ? $origemId : null, $veio ? $origemNome : null,
                    ]);
                }
            }

            $this->_conexao->commit();

            return $idPartida;
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Marca um gol. Com $idJogador: gol dele (ou gol contra, que vale para o adversário).
     * Sem jogador: "não sei quem fez", e $lado diz para quem vale.
     */
    public function gol($idJogo, $idPartida, $idJogador, $lado, $contra = false)
    {
        $partida = $this->partidaDoJogo($idJogo, $idPartida);
        $this->exige($partida['situacao'] === 'em_andamento', 'Esta partida já foi encerrada.');

        if ((int) $idJogador > 0) {
            $stmt = $this->_conexao->prepare('SELECT lado FROM jogos_partidas_jogadores WHERE id_partida = ? AND id_jogador = ?');
            $stmt->execute([(int) $partida['id'], (int) $idJogador]);
            $linha = $stmt->fetch();
            $this->exige($linha, 'Esse jogador não está jogando esta partida.');

            $ladoGol = $contra ? ($linha['lado'] === 'a' ? 'b' : 'a') : $linha['lado'];
            $idJogador = (int) $idJogador;
        } else {
            $this->exige(in_array($lado, ['a', 'b'], true), 'Escolha o time que fez o gol.');
            $this->exige(!$contra, 'Gol contra precisa do jogador que o fez.');
            $ladoGol = $lado;
            $idJogador = null;
        }

        $segundos = max(0, time() - strtotime($partida['iniciada_em']));

        $stmt = $this->_conexao->prepare(
            'INSERT INTO jogos_gols (id_partida, id_jogador, lado, contra, segundos) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([(int) $partida['id'], $idJogador, $ladoGol, $contra ? 's' : 'n', $segundos]);
    }

    /** Apaga um gol marcado por engano (partida ainda em andamento). */
    public function desfazerGol($idJogo, $idGol)
    {
        $jogo = $this->jogoDoUsuario($idJogo);

        $stmt = $this->_conexao->prepare(
            "SELECT g.id, p.situacao
             FROM jogos_gols g JOIN jogos_partidas p ON p.id = g.id_partida
             WHERE g.id = ? AND p.id_jogo = ? AND g.excluido = 'n'"
        );
        $stmt->execute([(int) $idGol, (int) $jogo['id']]);
        $gol = $stmt->fetch();

        $this->exige($gol, 'Gol não encontrado.');
        $this->exige($gol['situacao'] === 'em_andamento', 'A partida já foi encerrada. Reabra-a para corrigir os gols.');

        $stmt = $this->_conexao->prepare("UPDATE jogos_gols SET excluido = 's' WHERE id = ?");
        $stmt->execute([(int) $gol['id']]);
    }

    /**
     * Encerra a partida. O placar sai dos gols.
     *
     * Com vencedor, o perdedor sai. Em empate é preciso decidir quem sai:
     *  - $ladoPar ('a' ou 'b'): par ou ímpar. O app sorteia um número de 0 a 5 para cada
     *    time (os "dedos"); se a soma for par, ganha o lado que ficou com o PAR, senão o outro.
     *    Quem ganha fica, quem perde sai;
     *  - ou $idTimeSaiu: o administrador escolhe o time que sai.
     *
     * @return array resultado (placar, empate, desempate e quem saiu/fica) para a mensagem da tela
     */
    public function encerrar($idJogo, $idPartida, $idTimeSaiu = 0, $ladoPar = '')
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $partida = $this->partidaDoJogo($id, $idPartida);
            $this->exige($partida['situacao'] === 'em_andamento', 'Esta partida já foi encerrada.');

            $stmt = $this->_conexao->prepare(
                "SELECT COALESCE(SUM(lado = 'a'), 0) AS a, COALESCE(SUM(lado = 'b'), 0) AS b
                 FROM jogos_gols WHERE id_partida = ? AND excluido = 'n'"
            );
            $stmt->execute([(int) $partida['id']]);
            $placar = $stmt->fetch();
            $golsA = (int) $placar['a'];
            $golsB = (int) $placar['b'];

            $desempate = [null, null, null, null]; // tipo, lado do par, número a, número b
            if ($golsA > $golsB) {
                $vencedor = 'a';
                $saiu = (int) $partida['id_time_b'];
            } elseif ($golsB > $golsA) {
                $vencedor = 'b';
                $saiu = (int) $partida['id_time_a'];
            } else {
                $vencedor = null;
                if (in_array($ladoPar, ['a', 'b'], true)) {
                    $numA = random_int(0, 5);
                    $numB = random_int(0, 5);
                    $ganhou = (($numA + $numB) % 2 === 0) ? $ladoPar : ($ladoPar === 'a' ? 'b' : 'a');
                    $saiu = (int) $partida['id_time_' . ($ganhou === 'a' ? 'b' : 'a')];
                    $desempate = ['par_impar', $ladoPar, $numA, $numB];
                } else {
                    $saiu = (int) $idTimeSaiu;
                    $this->exige(
                        $saiu === (int) $partida['id_time_a'] || $saiu === (int) $partida['id_time_b'],
                        'Deu empate: escolha o par ou ímpar, ou qual time sai da quadra.'
                    );
                    $desempate = ['manual', null, null, null];
                }
            }

            $stmt = $this->_conexao->prepare(
                "UPDATE jogos_partidas SET situacao = 'finalizada', finalizada_em = ?, gols_a = ?, gols_b = ?,
                        lado_vencedor = ?, id_time_saiu = ?, desempate = ?, desempate_lado_par = ?,
                        desempate_num_a = ?, desempate_num_b = ? WHERE id = ?"
            );
            $stmt->execute([
                date('Y-m-d H:i:s'), $golsA, $golsB, $vencedor, $saiu,
                $desempate[0], $desempate[1], $desempate[2], $desempate[3], (int) $partida['id'],
            ]);

            // Quem saiu perde o colete e os jogadores vão para o fim da lista, na ordem em que estavam.
            $this->mandaParaFila($id, $saiu, (int) $partida['id']);

            $this->_conexao->commit();

            $partida = $this->partidaDoJogo($id, $idPartida);
            $ladoSaiu = $saiu === (int) $partida['id_time_a'] ? 'a' : 'b';
            $ladoFica = $ladoSaiu === 'a' ? 'b' : 'a';

            return [
                'gols_a' => $golsA,
                'gols_b' => $golsB,
                'empate' => $vencedor === null,
                'desempate' => $this->descreveDesempate($partida),
                'saiu' => $partida['nome_' . $ladoSaiu],
                'colete_saiu' => $partida['colete_nome_' . $ladoSaiu],
                'fica' => $partida['nome_' . $ladoFica],
                'colete_fica' => $partida['colete_nome_' . $ladoFica],
            ];
        } catch (\Exception $e) {
            if ($this->_conexao->inTransaction()) {
                $this->_conexao->rollBack();
            }
            throw $e;
        }
    }

    /** Desfaz o encerramento da última partida (engano no botão). */
    public function reabrir($idJogo, $idPartida)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $partida = $this->partidaDoJogo($id, $idPartida);
            $this->exige($partida['situacao'] === 'finalizada', 'Só dá para reabrir uma partida encerrada.');

            $stmt = $this->_conexao->prepare(
                "SELECT 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao <> 'descartada' AND numero > ? LIMIT 1"
            );
            $stmt->execute([$id, (int) $partida['numero']]);
            $this->exige(!$stmt->fetch(), 'Já existe uma partida depois desta. Só a última pode ser reaberta.');

            // O time que saiu volta para a quadra, com os mesmos jogadores, na mesma ordem e com o colete que tinha.
            $saiu = (int) $partida['id_time_saiu'];
            $ladoSaiu = $saiu === (int) $partida['id_time_a'] ? 'a' : 'b';

            $stmt = $this->_conexao->prepare(
                'SELECT id_jogador, score, ordem FROM jogos_fila WHERE id_jogo = ? AND id_partida_saiu = ? ORDER BY ordem ASC, id ASC'
            );
            $stmt->execute([$id, (int) $partida['id']]);
            $volta = $this->_conexao->prepare(
                'INSERT INTO jogos_times_jogadores (id_time, id_jogador, score, ordem) VALUES (?, ?, ?, ?)'
            );
            foreach ($stmt->fetchAll() as $linha) {
                $volta->execute([$saiu, (int) $linha['id_jogador'], $linha['score'], (int) $linha['ordem']]);
            }
            $stmt = $this->_conexao->prepare('DELETE FROM jogos_fila WHERE id_jogo = ? AND id_partida_saiu = ?');
            $stmt->execute([$id, (int) $partida['id']]);
            $this->renumeraFila($id);

            $stmt = $this->_conexao->prepare('UPDATE jogos_times SET colete = ?, cor = ? WHERE id = ? AND id_jogo = ?');
            $stmt->execute([(int) $partida['colete_' . $ladoSaiu], $partida['cor_' . $ladoSaiu], $saiu, $id]);

            $stmt = $this->_conexao->prepare(
                "UPDATE jogos_partidas SET situacao = 'em_andamento', finalizada_em = NULL, lado_vencedor = NULL,
                        id_time_saiu = NULL, desempate = NULL, desempate_lado_par = NULL,
                        desempate_num_a = NULL, desempate_num_b = NULL WHERE id = ?"
            );
            $stmt->execute([(int) $partida['id']]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /** Descarta uma partida iniciada por engano: volta tudo como estava antes de ela começar. */
    public function descartar($idJogo, $idPartida)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $partida = $this->partidaDoJogo($id, $idPartida);
            $this->exige($partida['situacao'] === 'em_andamento', 'Só dá para descartar a partida em andamento.');

            // O time que entrou se desfaz: os jogadores voltam para o começo da lista, na mesma ordem.
            if ($partida['id_time_entrou']) {
                $entrou = (int) $partida['id_time_entrou'];

                $stmt = $this->_conexao->prepare(
                    "SELECT tj.id_jogador, tj.score, COALESCE(pj.emprestado, 'n') AS emprestado
                     FROM jogos_times_jogadores tj
                     LEFT JOIN jogos_partidas_jogadores pj ON pj.id_partida = ? AND pj.id_jogador = tj.id_jogador
                     WHERE tj.id_time = ? ORDER BY tj.ordem ASC, tj.id ASC"
                );
                $stmt->execute([(int) $partida['id'], $entrou]);
                $voltam = $stmt->fetchAll();

                // Partida de onde eles tinham saído (a última encerrada antes desta).
                $stmt = $this->_conexao->prepare(
                    "SELECT id FROM jogos_partidas WHERE id_jogo = ? AND situacao = 'finalizada' AND numero < ?
                     ORDER BY numero DESC, id DESC LIMIT 1"
                );
                $stmt->execute([$id, (int) $partida['numero']]);
                $anterior = $stmt->fetchColumn();

                $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ordem + ? WHERE id_jogo = ?');
                $stmt->execute([count($voltam), $id]);

                $stmt = $this->_conexao->prepare(
                    'INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score, id_partida_saiu) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($voltam as $i => $linha) {
                    $stmt->execute([
                        $id, (int) $linha['id_jogador'], $i + 1, $linha['score'],
                        ($linha['emprestado'] === 's' && $anterior) ? (int) $anterior : null,
                    ]);
                }

                $stmt = $this->_conexao->prepare('DELETE FROM jogos_times WHERE id = ? AND id_jogo = ?');
                $stmt->execute([$entrou, $id]);
                $this->renumeraFila($id);
            }

            $stmt = $this->_conexao->prepare("UPDATE jogos_partidas SET situacao = 'descartada' WHERE id = ?");
            $stmt->execute([(int) $partida['id']]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    // ================================================================ leitura

    /** Tudo de partidas para a tela do jogo (partida atual, próxima, histórico e artilheiros). */
    public function painel(array $jogo)
    {
        $id = (int) $jogo['id'];
        $atual = null;
        $historico = [];
        $ultimaReabrivel = null;

        $stmt = $this->_conexao->prepare(
            "SELECT * FROM jogos_partidas WHERE id_jogo = ? AND situacao <> 'descartada' ORDER BY numero DESC, id DESC"
        );
        $stmt->execute([$id]);
        $partidas = $stmt->fetchAll();

        $sequencias = $this->sequencias($partidas);

        foreach ($partidas as $p) {
            if ($p['situacao'] === 'em_andamento') {
                $atual = $this->detalhePartida($p);
                foreach (['a', 'b'] as $lado) {
                    $idTime = (int) $atual[$lado]['id_time'];
                    $atual[$lado]['sequencia'] = isset($sequencias[$idTime]) ? $sequencias[$idTime] : 0;
                }
                $atual['sai_padrao'] = null;
                if ($atual['a']['gols'] === $atual['b']['gols'] && $atual['a']['sequencia'] !== $atual['b']['sequencia']) {
                    $atual['sai_padrao'] = $atual['a']['sequencia'] > $atual['b']['sequencia'] ? 'a' : 'b';
                }
            } else {
                $historico[] = $this->detalhePartida($p);
            }
        }

        // Só a última partida encerrada pode ser reaberta, e só se não há outra em andamento.
        if (!$atual && $historico && in_array($jogo['situacao'], ['agendado', 'em_andamento'], true)) {
            $ultimaReabrivel = $historico[0]['id'];
        }

        return [
            'coletes' => $this->coletes($jogo),
            'partida_atual' => $atual,
            'proxima' => $this->plano($jogo),
            'fila' => $this->filaParaTela($jogo),
            'historico' => $historico,
            'partida_reabrivel' => $ultimaReabrivel,
            'artilheiros' => $this->artilheiros($id),
            'tem_partidas' => (bool) $partidas,
        ];
    }

    /** Ranking do grupo a partir das partidas encerradas. $periodo: 'tudo' | 'ano' | '30d'. */
    public function ranking($idGrupo, $periodo = 'tudo')
    {
        $this->grupoDoUsuario($idGrupo);

        $filtro = '';
        $params = [(int) $idGrupo];
        if ($periodo === 'ano') {
            $filtro = ' AND jo.data_hora >= ?';
            $params[] = date('Y-01-01 00:00:00');
        } elseif ($periodo === '30d') {
            $filtro = ' AND jo.data_hora >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }

        $stmt = $this->_conexao->prepare(
            "SELECT j.id AS id_jogador, j.nome,
                    COUNT(*) AS partidas,
                    COUNT(DISTINCT p.id_jogo) AS jogos,
                    COALESCE(SUM(p.lado_vencedor = pj.lado), 0) AS vitorias,
                    COALESCE(SUM(p.lado_vencedor IS NULL), 0) AS empates,
                    COALESCE(SUM(p.lado_vencedor IS NOT NULL AND p.lado_vencedor <> pj.lado), 0) AS derrotas
             FROM jogos_partidas_jogadores pj
             JOIN jogos_partidas p ON p.id = pj.id_partida AND p.situacao = 'finalizada'
             JOIN jogos jo ON jo.id = p.id_jogo AND jo.id_grupo = ?{$filtro}
             JOIN jogadores j ON j.id = pj.id_jogador
             GROUP BY j.id, j.nome"
        );
        $stmt->execute($params);

        $linhas = [];
        foreach ($stmt->fetchAll() as $l) {
            $linhas[(int) $l['id_jogador']] = [
                'id_jogador' => (int) $l['id_jogador'],
                'nome' => $l['nome'],
                'partidas' => (int) $l['partidas'],
                'jogos' => (int) $l['jogos'],
                'vitorias' => (int) $l['vitorias'],
                'empates' => (int) $l['empates'],
                'derrotas' => (int) $l['derrotas'],
                'gols' => 0,
                'gols_contra' => 0,
            ];
        }

        $stmt = $this->_conexao->prepare(
            "SELECT g.id_jogador, COALESCE(SUM(g.contra = 'n'), 0) AS gols, COALESCE(SUM(g.contra = 's'), 0) AS contra
             FROM jogos_gols g
             JOIN jogos_partidas p ON p.id = g.id_partida AND p.situacao = 'finalizada'
             JOIN jogos jo ON jo.id = p.id_jogo AND jo.id_grupo = ?{$filtro}
             WHERE g.excluido = 'n' AND g.id_jogador IS NOT NULL
             GROUP BY g.id_jogador"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $g) {
            if (isset($linhas[(int) $g['id_jogador']])) {
                $linhas[(int) $g['id_jogador']]['gols'] = (int) $g['gols'];
                $linhas[(int) $g['id_jogador']]['gols_contra'] = (int) $g['contra'];
            }
        }

        foreach ($linhas as &$l) {
            $l['aproveitamento'] = (int) round(($l['vitorias'] * 3 + $l['empates']) / ($l['partidas'] * 3) * 100);
        }
        unset($l);

        $lista = array_values($linhas);
        usort($lista, function ($x, $y) {
            return [$y['gols'], $y['vitorias'], $y['aproveitamento'], $x['nome']] <=> [$x['gols'], $x['vitorias'], $x['aproveitamento'], $y['nome']];
        });

        $stmt = $this->_conexao->prepare(
            "SELECT COUNT(*) AS partidas, COUNT(DISTINCT p.id_jogo) AS jogos, COALESCE(SUM(p.gols_a + p.gols_b), 0) AS gols
             FROM jogos_partidas p JOIN jogos jo ON jo.id = p.id_jogo AND jo.id_grupo = ?{$filtro}
             WHERE p.situacao = 'finalizada'"
        );
        $stmt->execute($params);
        $tot = $stmt->fetch();

        return [
            'jogadores' => $lista,
            'totais' => ['partidas' => (int) $tot['partidas'], 'jogos' => (int) $tot['jogos'], 'gols' => (int) $tot['gols']],
        ];
    }

    // ================================================================ plano da próxima partida

    /**
     * Como será a próxima partida. null quando não há o que planejar (partida em
     * andamento, jogo não sorteado ou encerrado).
     *
     * Depois da primeira partida o time que entra são os primeiros da lista de espera:
     * quem estava de fora vem antes de quem acabou de sair, sempre na ordem da lista.
     */
    public function plano(array $jogo)
    {
        $id = (int) $jogo['id'];
        if ($jogo['situacao'] !== 'em_andamento' || $this->partidaAberta($id)) {
            return null;
        }

        $elenco = $this->elenco($id);
        $stmt = $this->_conexao->prepare(
            "SELECT * FROM jogos_partidas WHERE id_jogo = ? AND situacao = 'finalizada' ORDER BY numero DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $ultima = $stmt->fetch();

        $porTime = (int) $jogo['jogadores_por_time'];
        $coletes = $this->coletes($jogo);

        // ---- primeira partida: os dois times sorteados
        if (!$ultima) {
            $cheios = array_filter($elenco, function ($t) {
                return count($t['jogadores']) > 0;
            });
            if (count($cheios) < 2) {
                return null;
            }

            $a = $b = null;
            foreach ($cheios as $t) {
                if ((int) $t['colete'] === 1 && !$a) {
                    $a = $t;
                } elseif ((int) $t['colete'] === 2 && !$b) {
                    $b = $t;
                }
            }
            if (!$a || !$b) { // jogos sorteados antes dos coletes existirem
                $dois = array_slice(array_values($cheios), 0, 2);
                $a = $dois[0];
                $b = $dois[1];
            }

            return [
                'primeira' => true,
                'lado_a' => $this->resumoTime($a, $coletes[0]),
                'lado_b' => $this->resumoTime($b, $coletes[1]),
                'faltam' => 0,
                'por_time' => $porTime,
            ];
        }

        // ---- partidas seguintes
        $saiuId = (int) $ultima['id_time_saiu'];
        $ladoSaiu = $saiuId === (int) $ultima['id_time_a'] ? 'a' : 'b';
        $ficaId = $ladoSaiu === 'a' ? (int) $ultima['id_time_b'] : (int) $ultima['id_time_a'];
        $coleteLivre = (int) $ultima['colete_' . $ladoSaiu];
        $nomeSaiu = $ultima['nome_' . $ladoSaiu];

        $resultado = [
            'primeira' => false,
            'ultima' => [
                'id' => (int) $ultima['id'],
                'numero' => (int) $ultima['numero'],
                'nome_a' => $ultima['nome_a'], 'nome_b' => $ultima['nome_b'],
                'colete_nome_a' => $ultima['colete_nome_a'], 'colete_nome_b' => $ultima['colete_nome_b'],
                'cor_a' => $ultima['cor_a'], 'cor_b' => $ultima['cor_b'],
                'gols_a' => (int) $ultima['gols_a'], 'gols_b' => (int) $ultima['gols_b'],
                'empate' => $ultima['lado_vencedor'] === null,
                'desempate' => $this->descreveDesempate($ultima),
                'saiu' => $nomeSaiu,
                'colete_saiu' => $ultima['colete_nome_' . $ladoSaiu],
                'lado_saiu' => $ladoSaiu,
            ],
        ];

        if (!isset($elenco[$ficaId]) || !$elenco[$ficaId]['jogadores']) {
            return $resultado + ['erro' => 'O time que ficou na quadra está sem jogadores. Ajuste os times antes de continuar.'];
        }

        $fila = $this->filaDoJogo($id);
        if (!$fila) {
            return $resultado + ['erro' => 'Não há jogadores na lista de espera para formar o próximo time.'];
        }

        $entram = array_slice($fila, 0, $porTime);
        $jogadores = [];
        $soma = 0.0;
        $doSaiu = 0;
        foreach ($entram as $f) {
            $veio = (int) $f['id_partida_saiu'] === (int) $ultima['id'];
            $doSaiu += $veio ? 1 : 0;
            $soma += (float) $f['score'];
            $jogadores[] = [
                'id_jogador' => (int) $f['id_jogador'],
                'nome' => $f['nome'],
                'score' => (float) $f['score'],
                'ordem' => (int) $f['ordem'],
                'veio_do_saiu' => $veio,
                'origem' => $veio ? $nomeSaiu : null,
            ];
        }

        $stmt = $this->_conexao->prepare('SELECT COUNT(*) FROM jogos_times WHERE id_jogo = ?');
        $stmt->execute([$id]);
        $nomeNovo = 'Time ' . ((int) $stmt->fetchColumn() + 1);

        $coleteEntra = $coletes[$coleteLivre - 1];
        $restante = [];
        foreach (array_slice($fila, $porTime) as $f) {
            $restante[] = ['id_jogador' => (int) $f['id_jogador'], 'nome' => $f['nome']];
        }

        return $resultado + [
            'fica' => $this->resumoTime($elenco[$ficaId], $coletes[2 - $coleteLivre]),
            'entra' => [
                'id' => 0,
                'nome' => $nomeNovo,
                'jogadores' => $jogadores,
                'soma' => round($soma, 1),
                'colete' => (int) $coleteEntra['numero'],
                'colete_nome' => $coleteEntra['nome'],
                'cor' => $coleteEntra['cor'],
            ],
            'saiu' => ['id' => $saiuId, 'nome' => $nomeSaiu, 'colete' => $coleteLivre],
            'colete_entra' => $coleteLivre,
            'de_fora' => count($jogadores) - $doSaiu,
            'do_saiu' => $doSaiu,
            'faltam' => max(0, $porTime - count($jogadores)),
            'por_time' => $porTime,
            'revanche' => $doSaiu === count($jogadores),
            'fila_restante' => $restante,
        ];
    }

    // ================================================================ auxiliares

    /** Trava a linha do jogo para que dois cliques seguidos não criem duas partidas. */
    private function travaJogo($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT id FROM jogos WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $idJogo]);
    }

    private function partidaAberta($idJogo)
    {
        $stmt = $this->_conexao->prepare("SELECT 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao = 'em_andamento' LIMIT 1");
        $stmt->execute([(int) $idJogo]);

        return (bool) $stmt->fetch();
    }

    /** Partida do jogo (⚠️ multi-tenant: o jogo precisa ser do usuário logado). */
    private function partidaDoJogo($idJogo, $idPartida)
    {
        $jogo = $this->jogoDoUsuario($idJogo);

        $stmt = $this->_conexao->prepare('SELECT * FROM jogos_partidas WHERE id = ? AND id_jogo = ?');
        $stmt->execute([(int) $idPartida, (int) $jogo['id']]);
        $partida = $stmt->fetch();

        $this->exige($partida && $partida['situacao'] !== 'descartada', 'Partida não encontrada.');

        return $partida;
    }

    private function coletes(array $jogo)
    {
        return [
            ['numero' => 1, 'nome' => $jogo['colete1_nome'], 'cor' => $jogo['colete1_cor']],
            ['numero' => 2, 'nome' => $jogo['colete2_nome'], 'cor' => $jogo['colete2_cor']],
        ];
    }

    private function defineColete($idTime, $numero, array $jogo)
    {
        $stmt = $this->_conexao->prepare('UPDATE jogos_times SET colete = ?, cor = ? WHERE id = ? AND id_jogo = ?');
        $stmt->execute([(int) $numero, $jogo['colete' . (int) $numero . '_cor'], (int) $idTime, (int) $jogo['id']]);
    }

    /** A lista de espera numerada de novo em 1, 2, 3… sem mudar a ordem. */
    private function renumeraFila($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT id FROM jogos_fila WHERE id_jogo = ? ORDER BY ordem ASC, id ASC');
        $stmt->execute([(int) $idJogo]);
        $upd = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ? WHERE id = ?');
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $i => $idLinha) {
            $upd->execute([$i + 1, (int) $idLinha]);
        }
    }

    /** Lista de espera do jogo, do primeiro ao último. */
    private function filaDoJogo($idJogo)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT f.id_jogador, j.nome, f.score, f.ordem, f.id_partida_saiu, p.numero AS saiu_numero
             FROM jogos_fila f
             JOIN jogadores j ON j.id = f.id_jogador
             LEFT JOIN jogos_partidas p ON p.id = f.id_partida_saiu
             WHERE f.id_jogo = ? ORDER BY f.ordem ASC, f.id ASC'
        );
        $stmt->execute([(int) $idJogo]);

        return $stmt->fetchAll();
    }

    /** A lista como a tela mostra: posição, bloco (de quantos jogadores forma um time) e de onde cada um saiu. */
    private function filaParaTela(array $jogo)
    {
        $porTime = max(1, (int) $jogo['jogadores_por_time']);
        $lista = [];
        foreach ($this->filaDoJogo((int) $jogo['id']) as $i => $f) {
            $lista[] = [
                'id_jogador' => (int) $f['id_jogador'],
                'nome' => $f['nome'],
                'score' => (float) $f['score'],
                'posicao' => $i + 1,
                'bloco' => intdiv($i, $porTime) + 1,
                'saiu_partida' => $f['saiu_numero'] === null ? null : (int) $f['saiu_numero'],
            ];
        }

        return $lista;
    }

    /**
     * Cria o time que entra na quadra com os jogadores tirados da frente da lista,
     * já com o colete de quem saiu.
     *
     * @return int id do time criado
     */
    private function formaTimeQueEntra(array $jogo, array $entra, $numeroColete)
    {
        $id = (int) $jogo['id'];

        $stmt = $this->_conexao->prepare(
            'INSERT INTO jogos_times (id_jogo, nome, cor, posicao, colete) VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->execute([$id, $entra['nome'], $jogo['colete' . (int) $numeroColete . '_cor'], (int) $numeroColete]);
        $idTime = (int) $this->_conexao->lastInsertId();

        $novo = $this->_conexao->prepare(
            'INSERT INTO jogos_times_jogadores (id_time, id_jogador, score, ordem) VALUES (?, ?, ?, ?)'
        );
        $ids = [];
        foreach ($entra['jogadores'] as $jg) {
            $novo->execute([$idTime, (int) $jg['id_jogador'], $jg['score'], (int) $jg['ordem']]);
            $ids[] = (int) $jg['id_jogador'];
        }

        $stmt = $this->_conexao->prepare(
            'DELETE FROM jogos_fila WHERE id_jogo = ? AND id_jogador IN (' . $this->placeholders($ids) . ')'
        );
        $stmt->execute(array_merge([$id], $ids));
        $this->renumeraFila($id);

        return $idTime;
    }

    /** O time sai da quadra: perde o colete e os jogadores vão para o fim da lista, na ordem do time. */
    private function mandaParaFila($idJogo, $idTime, $idPartida)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT id_jogador, score FROM jogos_times_jogadores WHERE id_time = ? ORDER BY ordem ASC, id ASC'
        );
        $stmt->execute([(int) $idTime]);
        $jogadores = $stmt->fetchAll();

        $stmt = $this->_conexao->prepare('SELECT COALESCE(MAX(ordem), 0) FROM jogos_fila WHERE id_jogo = ?');
        $stmt->execute([(int) $idJogo]);
        $ordem = (int) $stmt->fetchColumn();

        $novo = $this->_conexao->prepare(
            'INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score, id_partida_saiu) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($jogadores as $jg) {
            $novo->execute([(int) $idJogo, (int) $jg['id_jogador'], ++$ordem, $jg['score'], (int) $idPartida]);
        }

        $stmt = $this->_conexao->prepare('DELETE FROM jogos_times_jogadores WHERE id_time = ?');
        $stmt->execute([(int) $idTime]);

        $stmt = $this->_conexao->prepare('UPDATE jogos_times SET colete = NULL, cor = ?, posicao = 0 WHERE id = ? AND id_jogo = ?');
        $stmt->execute([Times::COR_FILA, (int) $idTime, (int) $idJogo]);

        $this->renumeraFila($idJogo);
    }

    /** Como o empate foi decidido (null se a partida teve vencedor). */
    private function descreveDesempate(array $p)
    {
        if (empty($p['desempate'])) {
            return null;
        }
        if ($p['desempate'] === 'manual') {
            return ['tipo' => 'manual'];
        }

        $numA = (int) $p['desempate_num_a'];
        $numB = (int) $p['desempate_num_b'];
        $soma = $numA + $numB;
        $par = $soma % 2 === 0;
        $ladoPar = $p['desempate_lado_par'];
        $ladoImpar = $ladoPar === 'a' ? 'b' : 'a';

        return [
            'tipo' => 'par_impar',
            'lado_par' => $ladoPar,
            'lado_impar' => $ladoImpar,
            'num_a' => $numA,
            'num_b' => $numB,
            'soma' => $soma,
            'resultado' => $par ? 'par' : 'impar',
            'lado_ganhou' => $par ? $ladoPar : $ladoImpar,
        ];
    }

    /** Times que têm colete ou jogadores (os que saíram ficam vazios), indexados pelo id, com jogadores na ordem da lista. */
    private function elenco($idJogo)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT id, nome, cor, posicao, colete FROM jogos_times WHERE id_jogo = ?
             ORDER BY (colete IS NULL), colete ASC, posicao ASC, id ASC'
        );
        $stmt->execute([(int) $idJogo]);

        $elenco = [];
        foreach ($stmt->fetchAll() as $t) {
            $t['id'] = (int) $t['id'];
            $t['colete'] = $t['colete'] === null ? null : (int) $t['colete'];
            $t['jogadores'] = [];
            $t['soma'] = 0.0;
            $elenco[$t['id']] = $t;
        }
        if (!$elenco) {
            return [];
        }

        $stmt = $this->_conexao->prepare(
            'SELECT tj.id_time, tj.id_jogador, j.nome, tj.score, tj.ordem
             FROM jogos_times_jogadores tj JOIN jogadores j ON j.id = tj.id_jogador
             WHERE tj.id_time IN (' . $this->placeholders(array_keys($elenco)) . ')
             ORDER BY tj.ordem ASC, j.nome ASC'
        );
        $stmt->execute(array_keys($elenco));
        foreach ($stmt->fetchAll() as $l) {
            $elenco[(int) $l['id_time']]['jogadores'][] = [
                'id_jogador' => (int) $l['id_jogador'],
                'nome' => $l['nome'],
                'score' => (float) $l['score'],
                'ordem' => (int) $l['ordem'],
            ];
            $elenco[(int) $l['id_time']]['soma'] += (float) $l['score'];
        }

        return $elenco;
    }

    private function resumoTime(array $time, $colete)
    {
        return [
            'id' => (int) $time['id'],
            'nome' => $time['nome'],
            'jogadores' => $time['jogadores'],
            'soma' => round($time['soma'], 1),
            'colete' => $colete ? (int) $colete['numero'] : null,
            'colete_nome' => $colete ? $colete['nome'] : null,
            'cor' => $colete ? $colete['cor'] : Times::COR_FILA,
        ];
    }

    /** Quantas partidas seguidas cada time jogou até agora (a mais recente encerrada conta primeiro). */
    private function sequencias(array $partidasDecrescentes)
    {
        $seq = [];
        $vivos = null;

        foreach ($partidasDecrescentes as $p) {
            if ($p['situacao'] !== 'finalizada') {
                continue;
            }
            $times = [(int) $p['id_time_a'], (int) $p['id_time_b']];
            if ($vivos === null) {
                $vivos = $times;
                foreach ($times as $t) {
                    $seq[$t] = 1;
                }
                continue;
            }
            foreach ($vivos as $k => $t) {
                if (in_array($t, $times, true)) {
                    $seq[$t]++;
                } else {
                    unset($vivos[$k]);
                }
            }
            if (!$vivos) {
                break;
            }
        }

        return $seq;
    }

    /** Uma partida com os dois lados, escalações e gols. */
    private function detalhePartida(array $p)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT pj.id_jogador, j.nome, pj.lado, pj.emprestado, pj.nome_time_origem
             FROM jogos_partidas_jogadores pj JOIN jogadores j ON j.id = pj.id_jogador
             WHERE pj.id_partida = ? ORDER BY j.nome ASC'
        );
        $stmt->execute([(int) $p['id']]);
        $jogadores = ['a' => [], 'b' => []];
        foreach ($stmt->fetchAll() as $l) {
            $jogadores[$l['lado']][] = [
                'id_jogador' => (int) $l['id_jogador'],
                'nome' => $l['nome'],
                'emprestado' => $l['emprestado'] === 's',
                'origem' => $l['nome_time_origem'],
            ];
        }

        $stmt = $this->_conexao->prepare(
            "SELECT g.id, g.id_jogador, j.nome, g.lado, g.contra, g.segundos
             FROM jogos_gols g LEFT JOIN jogadores j ON j.id = g.id_jogador
             WHERE g.id_partida = ? AND g.excluido = 'n' ORDER BY g.criado_em ASC, g.id ASC"
        );
        $stmt->execute([(int) $p['id']]);
        $gols = [];
        $placar = ['a' => 0, 'b' => 0];
        foreach ($stmt->fetchAll() as $g) {
            $placar[$g['lado']]++;
            $gols[] = [
                'id' => (int) $g['id'],
                'id_jogador' => $g['id_jogador'] === null ? null : (int) $g['id_jogador'],
                'nome' => $g['nome'],
                'lado' => $g['lado'],
                'contra' => $g['contra'] === 's',
                'segundos' => (int) $g['segundos'],
                'minuto' => intdiv((int) $g['segundos'], 60) + 1,
            ];
        }

        $emAndamento = $p['situacao'] === 'em_andamento';
        $inicio = strtotime($p['iniciada_em']);
        $fim = $emAndamento ? time() : strtotime($p['finalizada_em']);

        $lado = function ($l) use ($p, $jogadores, $placar) {
            return [
                'id_time' => (int) $p['id_time_' . $l],
                'entrou' => $p['id_time_entrou'] !== null && (int) $p['id_time_entrou'] === (int) $p['id_time_' . $l],
                'nome' => $p['nome_' . $l],
                'colete' => (int) $p['colete_' . $l],
                'colete_nome' => $p['colete_nome_' . $l],
                'cor' => $p['cor_' . $l],
                'gols' => $placar[$l],
                'jogadores' => $jogadores[$l],
            ];
        };

        return [
            'id' => (int) $p['id'],
            'numero' => (int) $p['numero'],
            'situacao' => $p['situacao'],
            'hora' => substr($p['iniciada_em'], 11, 5),
            'inicio' => $p['iniciada_em'],
            'hora_fim' => $emAndamento ? null : substr($p['finalizada_em'], 11, 5),
            'segundos' => max(0, $fim - $inicio),
            'lado_vencedor' => $p['lado_vencedor'],
            'id_time_saiu' => $p['id_time_saiu'] ? (int) $p['id_time_saiu'] : null,
            'lado_saiu' => !$p['id_time_saiu'] ? null : ((int) $p['id_time_saiu'] === (int) $p['id_time_a'] ? 'a' : 'b'),
            'desempate' => $this->descreveDesempate($p),
            'a' => $lado('a'),
            'b' => $lado('b'),
            'gols' => $gols,
        ];
    }

    /** Relatório de um jogo do usuário logado, com o cabeçalho para a tela. */
    public function relatorioDoJogo($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);

        return $this->relatorio($jogo) + [
            'jogo' => [
                'id' => (int) $jogo['id'],
                'id_grupo' => (int) $jogo['id_grupo'],
                'grupo_nome' => $jogo['grupo_nome'],
                'data_fmt' => dataHoraBr($jogo['data_hora']),
                'situacao' => $jogo['situacao'],
                'arena_nome' => $jogo['arena_nome'],
                'jogadores_por_time' => (int) $jogo['jogadores_por_time'],
                'coletes' => $this->coletes($jogo),
            ],
        ];
    }

    /**
     * Relatório do jogo: todas as partidas com tudo o que aconteceu e o desempenho de cada jogador.
     */
    public function relatorio(array $jogo)
    {
        $id = (int) $jogo['id'];

        $stmt = $this->_conexao->prepare(
            "SELECT * FROM jogos_partidas WHERE id_jogo = ? AND situacao <> 'descartada' ORDER BY numero ASC, id ASC"
        );
        $stmt->execute([$id]);
        $partidas = [];
        foreach ($stmt->fetchAll() as $p) {
            $partidas[] = $this->detalhePartida($p);
        }

        // Quem chegou (a ordem de chegada é a lista do começo da noite).
        $stmt = $this->_conexao->prepare(
            "SELECT p.id_jogador, j.nome, p.chegada_em
             FROM jogos_presencas p JOIN jogadores j ON j.id = p.id_jogador
             WHERE p.id_jogo = ? AND p.resposta = 'confirmado' AND p.chegada_em IS NOT NULL
             ORDER BY p.chegada_em ASC, p.id ASC"
        );
        $stmt->execute([$id]);

        $jogadores = [];
        $novoJogador = function ($idJogador, $nome, $chegada, $ordem) {
            return [
                'id_jogador' => (int) $idJogador,
                'nome' => $nome,
                'ordem_chegada' => $ordem,
                'chegada_hora' => $chegada ? substr($chegada, 11, 5) : null,
                'chegada_ts' => $chegada ? strtotime(substr($chegada, 0, 19)) : null,
                'partidas' => 0, 'vitorias' => 0, 'empates' => 0, 'derrotas' => 0,
                'gols' => 0, 'gols_contra' => 0,
                'segundos' => 0, 'esperou' => 0,
                'sequencia' => [],
                'jogos' => [],
            ];
        };
        $ordem = 0;
        foreach ($stmt->fetchAll() as $l) {
            $jogadores[(int) $l['id_jogador']] = $novoJogador($l['id_jogador'], $l['nome'], $l['chegada_em'], ++$ordem);
        }

        $encerradas = 0;
        $golsTotal = 0;
        $segundosTotal = 0;
        $emAndamento = false;

        foreach ($partidas as $p) {
            if ($p['situacao'] !== 'finalizada') {
                $emAndamento = true;
                continue;
            }
            $encerradas++;
            $golsTotal += $p['a']['gols'] + $p['b']['gols'];
            $segundosTotal += $p['segundos'];

            $jogaram = [];
            foreach (['a', 'b'] as $l) {
                $outro = $l === 'a' ? 'b' : 'a';
                foreach ($p[$l]['jogadores'] as $jg) {
                    $idJog = $jg['id_jogador'];
                    $jogaram[$idJog] = true;
                    if (!isset($jogadores[$idJog])) {
                        $jogadores[$idJog] = $novoJogador($idJog, $jg['nome'], null, null);
                    }
                    $r = $p['lado_vencedor'] === null ? 'E' : ($p['lado_vencedor'] === $l ? 'V' : 'D');

                    $meusGols = [];
                    $contra = 0;
                    foreach ($p['gols'] as $g) {
                        if ($g['id_jogador'] !== $idJog) {
                            continue;
                        }
                        if ($g['contra']) {
                            $contra++;
                        } else {
                            $meusGols[] = $g['minuto'];
                        }
                    }

                    $x = &$jogadores[$idJog];
                    $x['partidas']++;
                    $x['vitorias'] += $r === 'V' ? 1 : 0;
                    $x['empates'] += $r === 'E' ? 1 : 0;
                    $x['derrotas'] += $r === 'D' ? 1 : 0;
                    $x['gols'] += count($meusGols);
                    $x['gols_contra'] += $contra;
                    $x['segundos'] += $p['segundos'];
                    $x['sequencia'][] = $r;
                    $x['jogos'][] = [
                        'numero' => $p['numero'],
                        'colete' => $p[$l]['colete'],
                        'colete_nome' => $p[$l]['colete_nome'],
                        'cor' => $p[$l]['cor'],
                        'time' => $p[$l]['nome'],
                        'contra_colete_nome' => $p[$outro]['colete_nome'],
                        'gols_pro' => $p[$l]['gols'],
                        'gols_contra' => $p[$outro]['gols'],
                        'resultado' => $r,
                        'gols' => $meusGols,
                        'gols_contra_proprio' => $contra,
                        'veio_do_saiu' => $jg['emprestado'],
                        'origem' => $jg['origem'],
                        'entrou' => $p[$l]['entrou'],
                    ];
                    unset($x);
                }
            }

            // Quem já tinha chegado e ficou de fora desta partida.
            $inicio = strtotime($p['inicio']);
            foreach ($jogadores as $idJog => &$x) {
                if (!isset($jogaram[$idJog]) && $x['chegada_ts'] !== null && $x['chegada_ts'] <= $inicio) {
                    $x['esperou']++;
                }
            }
            unset($x);
        }

        foreach ($jogadores as &$x) {
            $x['aproveitamento'] = $x['partidas'] > 0
                ? (int) round(($x['vitorias'] * 3 + $x['empates']) / ($x['partidas'] * 3) * 100)
                : null;
            unset($x['chegada_ts']);
        }
        unset($x);

        $lista = array_values($jogadores);
        usort($lista, function ($x, $y) {
            return [$y['gols'], $y['vitorias'], $y['partidas'], $x['nome']] <=> [$x['gols'], $x['vitorias'], $x['partidas'], $y['nome']];
        });

        $ultimaFim = null;
        foreach ($partidas as $p) {
            if ($p['situacao'] === 'finalizada') {
                $ultimaFim = $p['hora_fim'];
            }
        }

        return [
            'partidas' => $partidas,
            'jogadores' => $lista,
            'totais' => [
                'partidas' => $encerradas,
                'gols' => $golsTotal,
                'segundos' => $segundosTotal,
                'jogadores' => count($lista),
                'primeira_hora' => $partidas ? $partidas[0]['hora'] : null,
                'ultima_hora' => $ultimaFim,
                'em_andamento' => $emAndamento,
            ],
        ];
    }

    private function artilheiros($idJogo)
    {
        $stmt = $this->_conexao->prepare(
            "SELECT g.id_jogador, j.nome, COALESCE(SUM(g.contra = 'n'), 0) AS gols, COALESCE(SUM(g.contra = 's'), 0) AS contra
             FROM jogos_gols g
             JOIN jogos_partidas p ON p.id = g.id_partida AND p.id_jogo = ? AND p.situacao <> 'descartada'
             JOIN jogadores j ON j.id = g.id_jogador
             WHERE g.excluido = 'n'
             GROUP BY g.id_jogador, j.nome
             ORDER BY gols DESC, j.nome ASC"
        );
        $stmt->execute([(int) $idJogo]);

        $lista = [];
        foreach ($stmt->fetchAll() as $l) {
            $lista[] = [
                'id_jogador' => (int) $l['id_jogador'],
                'nome' => $l['nome'],
                'gols' => (int) $l['gols'],
                'contra' => (int) $l['contra'],
            ];
        }

        return $lista;
    }
}
