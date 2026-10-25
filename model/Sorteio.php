<?php

namespace Model;

/**
 * Divide jogadores em times com a soma dos scores o mais parecida possível.
 *
 *  1. Roda várias tentativas aleatórias. Cada uma começa com uma divisão ao
 *     acaso e melhora trocando jogadores entre times enquanto a diferença
 *     entre o time mais forte e o mais fraco diminuir.
 *  2. Entre as tentativas, guarda as que ficaram (quase) tão equilibradas
 *     quanto a melhor e sorteia uma delas. O resultado é balanceado, mas muda
 *     a cada sorteio.
 *
 * Classe pura (sem banco): recebe [id => score] e devolve listas de ids.
 */
final class Sorteio
{
    private $_tentativas;
    private $_tolerancia;

    /**
     * @param int   $tentativas Quantas divisões aleatórias testar.
     * @param float $tolerancia Diferença extra aceita em relação à melhor combinação.
     */
    public function __construct($tentativas = 150, $tolerancia = 0.5)
    {
        $this->_tentativas = (int) $tentativas;
        $this->_tolerancia = (float) $tolerancia;
    }

    /**
     * @param array    $scores   [idJogador => score]
     * @param int      $qtdTimes
     * @param int|null $semente  Só para testes: mesma semente = mesmo resultado.
     * @return array[]           Times, cada um com os ids (maior score primeiro).
     * @throws \InvalidArgumentException
     */
    public function balancear(array $scores, $qtdTimes, $semente = null)
    {
        $qtdTimes = (int) $qtdTimes;
        $n = count($scores);

        if ($qtdTimes < 2) {
            throw new \InvalidArgumentException('São necessários pelo menos 2 times.');
        }
        if ($n < $qtdTimes) {
            throw new \InvalidArgumentException('Há menos jogadores do que times.');
        }

        // Semente fixa (testes) ou aleatória (reinicia qualquer semente anterior).
        mt_srand($semente !== null ? (int) $semente : random_int(1, 2147483647));

        $scores = array_map('floatval', $scores);
        $ids = array_keys($scores);

        // Tamanho de cada time (diferem em no máximo 1 jogador).
        $capacidade = [];
        for ($t = 0; $t < $qtdTimes; $t++) {
            $capacidade[$t] = intdiv($n, $qtdTimes) + ($t < $n % $qtdTimes ? 1 : 0);
        }

        $candidatos = [];
        $melhor = INF;

        for ($tentativa = 0; $tentativa < $this->_tentativas; $tentativa++) {
            $ordem = $this->embaralhar($ids);
            $times = [];
            $somas = [];
            $inicio = 0;

            for ($t = 0; $t < $qtdTimes; $t++) {
                $times[$t] = array_slice($ordem, $inicio, $capacidade[$t]);
                $somas[$t] = 0.0;
                foreach ($times[$t] as $id) {
                    $somas[$t] += $scores[$id];
                }
                $inicio += $capacidade[$t];
            }

            $this->melhorar($times, $somas, $scores);

            $diferenca = max($somas) - min($somas);
            $melhor = min($melhor, $diferenca);
            $candidatos[] = [$diferenca, $times];
        }

        $limite = $melhor + $this->_tolerancia + 1e-9;
        $bons = [];
        foreach ($candidatos as $c) {
            if ($c[0] <= $limite) {
                $bons[] = $c[1];
            }
        }

        $escolhido = $this->embaralhar($bons[mt_rand(0, count($bons) - 1)]);

        $resultado = [];
        foreach ($escolhido as $time) {
            usort($time, function ($a, $b) use ($scores) {
                return $scores[$b] <=> $scores[$a];
            });
            $resultado[] = array_values($time);
        }

        return $resultado;
    }

    /** Diferença entre o time mais forte e o mais fraco. */
    public static function diferenca(array $times, array $scores)
    {
        $somas = [];
        foreach ($times as $time) {
            $soma = 0.0;
            foreach ($time as $id) {
                $soma += (float) $scores[$id];
            }
            $somas[] = $soma;
        }

        return (float) (max($somas) - min($somas));
    }

    /** Troca jogadores entre times (uma troca que melhora, escolhida ao acaso) até não haver mais. */
    private function melhorar(array &$times, array &$somas, array $scores)
    {
        $qtd = count($times);

        for ($iteracao = 0; $iteracao < 200; $iteracao++) {
            $atual = $this->objetivo($somas);
            $melhoram = [];

            for ($a = 0; $a < $qtd; $a++) {
                for ($b = $a + 1; $b < $qtd; $b++) {
                    foreach ($times[$a] as $i => $jogadorA) {
                        foreach ($times[$b] as $j => $jogadorB) {
                            $delta = $scores[$jogadorB] - $scores[$jogadorA];
                            if (abs($delta) < 1e-9) {
                                continue; // trocar iguais não muda nada
                            }

                            $novas = $somas;
                            $novas[$a] += $delta;
                            $novas[$b] -= $delta;

                            if ($this->melhor($this->objetivo($novas), $atual)) {
                                $melhoram[] = [$a, $i, $b, $j, $delta];
                            }
                        }
                    }
                }
            }

            if (!$melhoram) {
                return;
            }

            list($a, $i, $b, $j, $delta) = $melhoram[mt_rand(0, count($melhoram) - 1)];
            $tmp = $times[$a][$i];
            $times[$a][$i] = $times[$b][$j];
            $times[$b][$j] = $tmp;
            $somas[$a] += $delta;
            $somas[$b] -= $delta;
        }
    }

    /** [diferença máx-mín, soma dos quadrados] */
    private function objetivo(array $somas)
    {
        $quadrados = 0.0;
        foreach ($somas as $s) {
            $quadrados += $s * $s;
        }

        return [max($somas) - min($somas), $quadrados];
    }

    private function melhor(array $a, array $b)
    {
        if ($a[0] < $b[0] - 1e-9) {
            return true;
        }

        return abs($a[0] - $b[0]) <= 1e-9 && $a[1] < $b[1] - 1e-9;
    }

    /** Fisher-Yates com mt_rand (respeita mt_srand). */
    private function embaralhar(array $lista)
    {
        $lista = array_values($lista);
        for ($i = count($lista) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $lista[$i];
            $lista[$i] = $lista[$j];
            $lista[$j] = $tmp;
        }

        return $lista;
    }
}
