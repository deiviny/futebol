<?php

namespace Model;

/**
 * Times da noite: sorteio e gestão da lista de espera.
 *
 * O sorteio por score vale só para a primeira formação: os primeiros
 * 2 x (jogadores por time) a chegar viram os dois primeiros times, equilibrados
 * pelo score, e cada um veste um dos dois coletes do grupo. Quem chegou depois
 * entra na LISTA DE ESPERA (jogos_fila), na ordem de chegada. Dali em diante a
 * lista manda: quem perde vai para o fim dela e o próximo time sai dos primeiros
 * da lista (ver Partidas).
 */
class Times extends Conect
{
    /** Cor dos times que estão na fila (sem colete). */
    const COR_FILA = '#8E8E93';

    /** Com gente para isso na chegada, o sorteio já forma até este número de times (ex.: 4 x 6 = 24). */
    const MAX_TIMES_SORTEIO = 4;

    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /**
     * Sorteia (ou sorteia de novo, substituindo o sorteio anterior).
     *
     * Com até 3 times completos de gente, os primeiros 2 x (por time) a chegar viram dois times
     * equilibrados e o resto espera na lista. Com 3 ou 4 times completos (ex.: 24 chegaram, 6 por
     * time), o sorteio já equilibra 3 ou 4 times: os dois que chegaram antes (em média) entram
     * em quadra e os outros vão para a lista, um time inteiro depois do outro, seguidos de quem
     * chegou além disso.
     *
     * @return int quantos jogadores ficaram na lista de espera
     */
    public function sortear($idJogo, $semente = null)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige(
            !$this->temPartidas((int) $jogo['id']),
            'Já existem partidas neste jogo. Para sortear de novo, descarte as partidas.'
        );

        $stmt = $this->_conexao->prepare(
            "SELECT p.id_jogador, COALESCE(gj.score, 5.0) AS score
             FROM jogos_presencas p
             LEFT JOIN grupos_jogadores gj ON gj.id_grupo = ? AND gj.id_jogador = p.id_jogador
             WHERE p.id_jogo = ? AND p.resposta = 'confirmado' AND p.chegada_em IS NOT NULL
             ORDER BY p.chegada_em ASC, p.id ASC"
        );
        $stmt->execute([(int) $jogo['id_grupo'], (int) $jogo['id']]);

        $scores = []; // [idJogador => score] na ordem de chegada
        foreach ($stmt->fetchAll() as $linha) {
            $scores[(int) $linha['id_jogador']] = (float) $linha['score'];
        }

        $this->exige(count($scores) >= 2, 'Marque a chegada de pelo menos 2 jogadores para sortear.');

        $porTime = (int) $jogo['jogadores_por_time'];
        $ids = array_keys($scores); // ordem de chegada

        $posicao = array_flip($ids); // idJogador => posição na ordem de chegada (0, 1, 2…)
        $qtdTimes = max(2, min(self::MAX_TIMES_SORTEIO, intdiv(count($ids), max(1, $porTime))));

        // Primeira formação: os primeiros a chegar, em times equilibrados pelo score.
        $primeiros = array_slice($ids, 0, $porTime * $qtdTimes);
        $parcial = [];
        foreach ($primeiros as $id) {
            $parcial[$id] = $scores[$id];
        }
        $times = (new Sorteio())->balancear($parcial, $qtdTimes, $semente);

        if ($qtdTimes > 2) {
            // Entram em quadra os dois times que chegaram antes, na média.
            usort($times, function ($x, $y) use ($posicao) {
                $media = function ($t) use ($posicao) {
                    return array_sum(array_map(function ($id) use ($posicao) { return $posicao[$id]; }, $t)) / max(1, count($t));
                };
                return $media($x) <=> $media($y);
            });
        }

        // Os times sorteados além dos dois primeiros esperam inteiros na lista, um depois
        // do outro; depois deles, quem chegou além disso, na ordem de chegada.
        $espera = [];
        foreach (array_slice($times, 2) as $time) {
            usort($time, function ($x, $y) use ($posicao) {
                return $posicao[$x] <=> $posicao[$y];
            });
            $espera = array_merge($espera, $time);
        }
        $espera = array_merge($espera, array_slice($ids, $porTime * $qtdTimes));
        $times = array_slice($times, 0, 2);

        $this->_conexao->beginTransaction();
        try {
            // Partidas descartadas não valem como histórico; saem junto com o sorteio antigo.
            $stmt = $this->_conexao->prepare('DELETE FROM jogos_partidas WHERE id_jogo = ?');
            $stmt->execute([(int) $jogo['id']]);

            $stmt = $this->_conexao->prepare('DELETE FROM jogos_times WHERE id_jogo = ?');
            $stmt->execute([(int) $jogo['id']]);

            $stmt = $this->_conexao->prepare('DELETE FROM jogos_fila WHERE id_jogo = ?');
            $stmt->execute([(int) $jogo['id']]);

            $novoTime = $this->_conexao->prepare(
                'INSERT INTO jogos_times (id_jogo, nome, cor, posicao, colete) VALUES (?, ?, ?, ?, ?)'
            );
            $novoJogador = $this->_conexao->prepare(
                'INSERT INTO jogos_times_jogadores (id_time, id_jogador, score, ordem) VALUES (?, ?, ?, ?)'
            );

            foreach ($times as $i => $time) {
                // Os dois times vestem os coletes 1 e 2.
                $colete = $i + 1;
                $novoTime->execute([(int) $jogo['id'], 'Time ' . ($i + 1), $jogo['colete' . $colete . '_cor'], $i + 1, $colete]);
                $idTime = (int) $this->_conexao->lastInsertId();

                // Dentro do time, a ordem é a da lista (ordem de chegada).
                usort($time, function ($x, $y) use ($posicao) {
                    return $posicao[$x] <=> $posicao[$y];
                });
                foreach ($time as $idJogador) {
                    $novoJogador->execute([$idTime, (int) $idJogador, $scores[$idJogador], $posicao[$idJogador] + 1]);
                }
            }

            $novoNaFila = $this->_conexao->prepare(
                'INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score) VALUES (?, ?, ?, ?)'
            );
            foreach ($espera as $i => $idJogador) {
                $novoNaFila->execute([(int) $jogo['id'], (int) $idJogador, $porTime * 2 + $i + 1, $scores[$idJogador]]);
            }

            $stmt = $this->_conexao->prepare("UPDATE jogos SET situacao = 'em_andamento' WHERE id = ?");
            $stmt->execute([(int) $jogo['id']]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return count($espera);
    }

    /**
     * Jogador que chegou depois do sorteio entra no fim da lista de espera.
     *
     * @return int posição dele na lista
     */
    public function adicionar($idJogo, $idJogador)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige($this->temTimes((int) $jogo['id']), 'Sorteie os times antes de incluir jogadores.');

        $stmt = $this->_conexao->prepare(
            "SELECT 1 FROM jogos_presencas
             WHERE id_jogo = ? AND id_jogador = ? AND resposta = 'confirmado' AND chegada_em IS NOT NULL"
        );
        $stmt->execute([(int) $jogo['id'], (int) $idJogador]);
        $this->exige($stmt->fetch(), 'Marque a chegada do jogador antes de incluí-lo na lista.');

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo((int) $jogo['id']);
            $this->exige(!$this->jogadorNoJogo((int) $jogo['id'], (int) $idJogador), 'Este jogador já está na lista ou em um time.');

            $stmt = $this->_conexao->prepare(
                'INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score)
                 VALUES (?, ?, (SELECT COALESCE(MAX(f.ordem), 0) + 1 FROM jogos_fila f WHERE f.id_jogo = ?),
                         COALESCE((SELECT score FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ?), 5.0))'
            );
            $stmt->execute([(int) $jogo['id'], (int) $idJogador, (int) $jogo['id'], (int) $jogo['id_grupo'], (int) $idJogador]);

            $posicao = $this->tamanhoDaFila((int) $jogo['id']);
            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return $posicao;
    }

    /**
     * Muda um jogador de lugar na lista de espera: 'subir', 'descer', 'inicio' ou 'fim'.
     * Com 'fila', tira da quadra o jogador de um time e põe no fim da lista (só entre uma partida e outra).
     */
    public function moverNaFila($idJogo, $idJogador, $direcao)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $this->exige(in_array($direcao, ['subir', 'descer', 'inicio', 'fim', 'fila'], true), 'Movimento inválido.');
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);

            if ($direcao === 'fila') {
                $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de tirar alguém da quadra.');

                $stmt = $this->_conexao->prepare(
                    'SELECT tj.id, tj.score FROM jogos_times_jogadores tj
                     JOIN jogos_times t ON t.id = tj.id_time
                     WHERE t.id_jogo = ? AND tj.id_jogador = ?'
                );
                $stmt->execute([$id, (int) $idJogador]);
                $linha = $stmt->fetch();
                $this->exige($linha, 'Este jogador não está em um time.');

                $stmt = $this->_conexao->prepare('DELETE FROM jogos_times_jogadores WHERE id = ?');
                $stmt->execute([(int) $linha['id']]);

                $stmt = $this->_conexao->prepare(
                    'INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score)
                     VALUES (?, ?, (SELECT COALESCE(MAX(f.ordem), 0) + 1 FROM jogos_fila f WHERE f.id_jogo = ?), ?)'
                );
                $stmt->execute([$id, (int) $idJogador, $id, $linha['score']]);
            } else {
                $fila = $this->idsDaFila($id);
                $indice = array_search((int) $idJogador, $fila, true);
                $this->exige($indice !== false, 'Este jogador não está na lista de espera.');

                array_splice($fila, $indice, 1);
                if ($direcao === 'inicio') {
                    $novo = 0;
                } elseif ($direcao === 'fim') {
                    $novo = count($fila);
                } elseif ($direcao === 'subir') {
                    $novo = max(0, $indice - 1);
                } else {
                    $novo = min(count($fila), $indice + 1);
                }
                array_splice($fila, $novo, 0, [(int) $idJogador]);

                $this->gravaOrdem($id, $fila);
            }

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Troca dois jogadores de lugar, para o administrador arrumar os times e a lista à mão
     * (ex.: alguém apertou um botão sem querer). Cada um fica com o lugar do outro: o time
     * e a posição dentro dele, ou a posição na lista de espera. Com partida em andamento só
     * dá para trocar quem está na lista; quem está em quadra espera a partida acabar.
     */
    public function trocar($idJogo, $idJogador, $idOutro)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];
        $this->exige((int) $idJogador !== (int) $idOutro, 'Escolha outro jogador para a troca.');

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $a = $this->lugarDoJogador($id, $idJogador);
            $b = $this->lugarDoJogador($id, $idOutro);
            $this->exige($a && $b, 'Os dois jogadores precisam estar em um time ou na lista de espera.');

            if ($a['onde'] === 'time' || $b['onde'] === 'time') {
                $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mexer nos times.');
            }

            if ($a['onde'] === 'fila' && $b['onde'] === 'fila') {
                // Cada um fica com a posição do outro (e leva consigo de que partida saiu).
                $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ? WHERE id = ?');
                $stmt->execute([$b['ordem'], $a['id']]);
                $stmt->execute([$a['ordem'], $b['id']]);
            } elseif ($a['onde'] === 'time' && $b['onde'] === 'time') {
                $this->exige($a['id_time'] !== $b['id_time'], 'Os dois já estão no mesmo time.');
                $stmt = $this->_conexao->prepare('UPDATE jogos_times_jogadores SET id_time = ?, ordem = ? WHERE id = ?');
                $stmt->execute([$b['id_time'], $b['ordem'], $a['id']]);
                $stmt->execute([$a['id_time'], $a['ordem'], $b['id']]);
            } else {
                // Um está no time e o outro na lista: quem estava no time assume a posição na lista.
                $noTime = $a['onde'] === 'time' ? $a : $b;
                $naFila = $a['onde'] === 'fila' ? $a : $b;

                $stmt = $this->_conexao->prepare('UPDATE jogos_times_jogadores SET id_jogador = ?, score = ? WHERE id = ?');
                $stmt->execute([$naFila['id_jogador'], $naFila['score'], $noTime['id']]);

                $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET id_jogador = ?, score = ?, id_partida_saiu = NULL WHERE id = ?');
                $stmt->execute([$noTime['id_jogador'], $noTime['score'], $naFila['id']]);
            }

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Passa um jogador (da lista de espera ou do outro time) para o fim de um time em quadra,
     * sem trocar com ninguém. Só entre uma partida e outra.
     */
    public function moverParaTime($idJogo, $idJogador, $idTime)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mexer nos times.');

            $stmt = $this->_conexao->prepare('SELECT id FROM jogos_times WHERE id = ? AND id_jogo = ? AND colete IS NOT NULL');
            $stmt->execute([(int) $idTime, $id]);
            $this->exige($stmt->fetch(), 'Time não encontrado.');

            $lugar = $this->lugarDoJogador($id, $idJogador);
            $this->exige($lugar, 'Este jogador não está em um time nem na lista de espera.');

            $stmt = $this->_conexao->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM jogos_times_jogadores WHERE id_time = ?');
            $stmt->execute([(int) $idTime]);
            $ordem = (int) $stmt->fetchColumn();

            if ($lugar['onde'] === 'time') {
                $this->exige($lugar['id_time'] !== (int) $idTime, 'O jogador já está neste time.');

                $stmt = $this->_conexao->prepare('UPDATE jogos_times_jogadores SET id_time = ?, ordem = ? WHERE id = ?');
                $stmt->execute([(int) $idTime, $ordem, $lugar['id']]);
            } else {
                $stmt = $this->_conexao->prepare('DELETE FROM jogos_fila WHERE id = ?');
                $stmt->execute([$lugar['id']]);

                $stmt = $this->_conexao->prepare(
                    'INSERT INTO jogos_times_jogadores (id_time, id_jogador, score, ordem) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([(int) $idTime, $lugar['id_jogador'], $lugar['score'], $ordem]);

                $this->gravaOrdem($id, $this->idsDaFila($id));
            }

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Muda quantos jogadores por time com a noite em andamento (ex.: começou com 5 e chegou
     * gente para 6). Vale para os próximos times que saírem da lista; os que estão em quadra
     * ficam como estão e o administrador completa (ou tira alguém) à mão.
     */
    public function alterarPorTime($idJogo, $porTime)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $porTime = (int) $porTime;
        $this->exige($porTime >= 2 && $porTime <= 11, 'Jogadores por time: de 2 a 11.');
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mudar quantos jogam por time.');

            $stmt = $this->_conexao->prepare('UPDATE jogos SET jogadores_por_time = ? WHERE id = ?');
            $stmt->execute([$porTime, $id]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Completa um time em quadra com os primeiros da lista de espera até ter os jogadores
     * por time do jogo. Só entre uma partida e outra.
     *
     * @return int quantos entraram
     */
    public function completarTime($idJogo, $idTime)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mexer nos times.');

            $stmt = $this->_conexao->prepare('SELECT id FROM jogos_times WHERE id = ? AND id_jogo = ? AND colete IS NOT NULL');
            $stmt->execute([(int) $idTime, $id]);
            $this->exige($stmt->fetch(), 'Time não encontrado.');

            $stmt = $this->_conexao->prepare('SELECT COUNT(*), COALESCE(MAX(ordem), 0) FROM jogos_times_jogadores WHERE id_time = ?');
            $stmt->execute([(int) $idTime]);
            list($qtd, $ordem) = array_map('intval', $stmt->fetch(\PDO::FETCH_NUM));

            $faltam = (int) $jogo['jogadores_por_time'] - $qtd;
            $this->exige($faltam > 0, 'O time já está completo.');

            $stmt = $this->_conexao->prepare(
                'SELECT id, id_jogador, score FROM jogos_fila WHERE id_jogo = ? ORDER BY ordem ASC, id ASC LIMIT ' . $faltam
            );
            $stmt->execute([$id]);
            $entram = $stmt->fetchAll();
            $this->exige($entram, 'Não há ninguém na lista de espera.');

            $novo = $this->_conexao->prepare(
                'INSERT INTO jogos_times_jogadores (id_time, id_jogador, score, ordem) VALUES (?, ?, ?, ?)'
            );
            $sai = $this->_conexao->prepare('DELETE FROM jogos_fila WHERE id = ?');
            foreach ($entram as $linha) {
                $novo->execute([(int) $idTime, (int) $linha['id_jogador'], $linha['score'], ++$ordem]);
                $sai->execute([(int) $linha['id']]);
            }
            $this->gravaOrdem($id, $this->idsDaFila($id));

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return count($entram);
    }

    /**
     * Esvazia os times em quadra para o administrador montá-los de novo, um jogador por vez.
     * Os jogadores vão para o começo da lista de espera, na ordem dos times, e os times
     * continuam existindo (vazios) com os mesmos coletes. Só entre uma partida e outra.
     *
     * @return int quantos jogadores voltaram para a lista
     */
    public function esvaziarTimes($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mexer nos times.');

            $stmt = $this->_conexao->prepare(
                'SELECT tj.id, tj.id_jogador, tj.score FROM jogos_times_jogadores tj
                 JOIN jogos_times t ON t.id = tj.id_time
                 WHERE t.id_jogo = ? AND t.colete IS NOT NULL
                 ORDER BY t.colete ASC, tj.ordem ASC, tj.id ASC'
            );
            $stmt->execute([$id]);
            $voltam = $stmt->fetchAll();
            $this->exige($voltam, 'Os times já estão vazios.');

            // Entre uma partida e outra o time que entra ainda não existe: cria-se vazio, com o colete livre.
            $this->criaTimesQueFaltam($jogo);

            $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ordem + ? WHERE id_jogo = ?');
            $stmt->execute([count($voltam), $id]);

            $sai = $this->_conexao->prepare('DELETE FROM jogos_times_jogadores WHERE id = ?');
            $entra = $this->_conexao->prepare('INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score) VALUES (?, ?, ?, ?)');
            foreach ($voltam as $i => $linha) {
                $sai->execute([(int) $linha['id']]);
                $entra->execute([$id, (int) $linha['id_jogador'], $i + 1, $linha['score']]);
            }
            $this->gravaOrdem($id, $this->idsDaFila($id));

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return count($voltam);
    }

    /**
     * Entre uma partida e outra, cria vazio o time que entra (com o colete livre), para o
     * administrador montá-lo à mão em vez de ele sair dos primeiros da lista.
     */
    public function montarTimeQueEntra($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento. Encerre-a antes de mexer nos times.');
            $this->exige($this->criaTimesQueFaltam($jogo) > 0, 'Os dois times já existem.');

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    /**
     * Desfaz o time que entra montado à mão: os jogadores voltam para o começo da lista e o
     * próximo time volta a sair dos primeiros da lista.
     */
    public function desfazerTimeQueEntra($idJogo)
    {
        $jogo = $this->jogoDoUsuario($idJogo);
        $this->exigeJogoAberto($jogo);
        $id = (int) $jogo['id'];

        $this->_conexao->beginTransaction();
        try {
            $this->travaJogo($id);
            $this->exige(!$this->partidaAberta($id), 'Há uma partida em andamento.');

            $stmt = $this->_conexao->prepare(
                "SELECT id_time_a, id_time_b, id_time_saiu FROM jogos_partidas
                 WHERE id_jogo = ? AND situacao = 'finalizada' ORDER BY numero DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$id]);
            $ultima = $stmt->fetch();
            $this->exige($ultima, 'Antes da primeira partida os dois times são os do sorteio.');
            $fica = (int) $ultima['id_time_saiu'] === (int) $ultima['id_time_a'] ? (int) $ultima['id_time_b'] : (int) $ultima['id_time_a'];

            $stmt = $this->_conexao->prepare('SELECT id FROM jogos_times WHERE id_jogo = ? AND colete IS NOT NULL AND id <> ?');
            $stmt->execute([$id, $fica]);
            $idTime = $stmt->fetchColumn();
            $this->exige($idTime, 'Não há time montado à mão para desfazer.');

            $stmt = $this->_conexao->prepare('SELECT id_jogador, score FROM jogos_times_jogadores WHERE id_time = ? ORDER BY ordem ASC, id ASC');
            $stmt->execute([(int) $idTime]);
            $voltam = $stmt->fetchAll();

            $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ordem + ? WHERE id_jogo = ?');
            $stmt->execute([count($voltam), $id]);
            $novo = $this->_conexao->prepare('INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score) VALUES (?, ?, ?, ?)');
            foreach ($voltam as $i => $linha) {
                $novo->execute([$id, (int) $linha['id_jogador'], $i + 1, $linha['score']]);
            }
            $stmt = $this->_conexao->prepare('DELETE FROM jogos_times_jogadores WHERE id_time = ?');
            $stmt->execute([(int) $idTime]);
            $stmt = $this->_conexao->prepare('DELETE FROM jogos_times WHERE id = ? AND id_jogo = ?');
            $stmt->execute([(int) $idTime, $id]);
            $this->gravaOrdem($id, $this->idsDaFila($id));

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------ auxiliares

    /**
     * Onde o jogador está no jogo: em um time ou na lista de espera (null se em nenhum).
     *
     * @return array|null ['onde' => 'time'|'fila', 'id' => linha, 'id_time', 'id_jogador', 'ordem', 'score']
     */
    private function lugarDoJogador($idJogo, $idJogador)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT tj.id, tj.id_time, tj.id_jogador, tj.ordem, tj.score FROM jogos_times_jogadores tj
             JOIN jogos_times t ON t.id = tj.id_time
             WHERE t.id_jogo = ? AND tj.id_jogador = ?'
        );
        $stmt->execute([(int) $idJogo, (int) $idJogador]);
        $linha = $stmt->fetch();
        if ($linha) {
            return ['onde' => 'time', 'id' => (int) $linha['id'], 'id_time' => (int) $linha['id_time'],
                'id_jogador' => (int) $linha['id_jogador'], 'ordem' => (int) $linha['ordem'], 'score' => $linha['score']];
        }

        $stmt = $this->_conexao->prepare('SELECT id, id_jogador, ordem, score FROM jogos_fila WHERE id_jogo = ? AND id_jogador = ?');
        $stmt->execute([(int) $idJogo, (int) $idJogador]);
        $linha = $stmt->fetch();
        if ($linha) {
            return ['onde' => 'fila', 'id' => (int) $linha['id'], 'id_time' => null,
                'id_jogador' => (int) $linha['id_jogador'], 'ordem' => (int) $linha['ordem'], 'score' => $linha['score']];
        }

        return null;
    }

    /** Cria vazio o time de cada colete que está sem time (só acontece entre uma partida e outra). @return int quantos criou */
    private function criaTimesQueFaltam(array $jogo)
    {
        $id = (int) $jogo['id'];
        $stmt = $this->_conexao->prepare('SELECT colete FROM jogos_times WHERE id_jogo = ? AND colete IS NOT NULL');
        $stmt->execute([$id]);
        $temColete = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (!$temColete) {
            return 0; // ainda não sorteado
        }

        $criados = 0;
        foreach ([1, 2] as $colete) {
            if (in_array($colete, $temColete, true)) {
                continue;
            }
            $stmt = $this->_conexao->prepare('SELECT COUNT(*) FROM jogos_times WHERE id_jogo = ?');
            $stmt->execute([$id]);
            $nome = 'Time ' . ((int) $stmt->fetchColumn() + 1);

            $stmt = $this->_conexao->prepare('INSERT INTO jogos_times (id_jogo, nome, cor, posicao, colete) VALUES (?, ?, ?, 0, ?)');
            $stmt->execute([$id, $nome, $jogo['colete' . $colete . '_cor'], $colete]);
            $criados++;
        }

        return $criados;
    }

    private function travaJogo($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT id FROM jogos WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $idJogo]);
    }

    /** @return int[] ids dos jogadores na lista, do primeiro ao último */
    private function idsDaFila($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT id_jogador FROM jogos_fila WHERE id_jogo = ? ORDER BY ordem ASC, id ASC');
        $stmt->execute([(int) $idJogo]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Grava a lista na ordem recebida (1, 2, 3…). */
    private function gravaOrdem($idJogo, array $idsJogadores)
    {
        $stmt = $this->_conexao->prepare('UPDATE jogos_fila SET ordem = ? WHERE id_jogo = ? AND id_jogador = ?');
        foreach ($idsJogadores as $i => $idJogador) {
            $stmt->execute([$i + 1, (int) $idJogo, (int) $idJogador]);
        }
    }

    private function tamanhoDaFila($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT COUNT(*) FROM jogos_fila WHERE id_jogo = ?');
        $stmt->execute([(int) $idJogo]);

        return (int) $stmt->fetchColumn();
    }

    private function partidaAberta($idJogo)
    {
        $stmt = $this->_conexao->prepare("SELECT 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao = 'em_andamento' LIMIT 1");
        $stmt->execute([(int) $idJogo]);

        return (bool) $stmt->fetch();
    }

    private function temPartidas($idJogo)
    {
        $stmt = $this->_conexao->prepare("SELECT 1 FROM jogos_partidas WHERE id_jogo = ? AND situacao <> 'descartada' LIMIT 1");
        $stmt->execute([(int) $idJogo]);

        return (bool) $stmt->fetch();
    }

    private function temTimes($idJogo)
    {
        $stmt = $this->_conexao->prepare('SELECT 1 FROM jogos_times WHERE id_jogo = ? LIMIT 1');
        $stmt->execute([(int) $idJogo]);

        return (bool) $stmt->fetch();
    }

    /** Já está em um time ou na lista de espera? */
    private function jogadorNoJogo($idJogo, $idJogador)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT 1 FROM jogos_times_jogadores tj JOIN jogos_times t ON t.id = tj.id_time
             WHERE t.id_jogo = ? AND tj.id_jogador = ?
             UNION ALL
             SELECT 1 FROM jogos_fila WHERE id_jogo = ? AND id_jogador = ?
             LIMIT 1'
        );
        $stmt->execute([(int) $idJogo, (int) $idJogador, (int) $idJogo, (int) $idJogador]);

        return (bool) $stmt->fetch();
    }
}
