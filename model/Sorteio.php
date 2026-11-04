<?php

namespace Model;

/**
 * Divide jogadores em times com a soma dos níveis o mais parecida possível.
 *
 * Cada jogador pode ter um número só (score) ou vários níveis, como
 * ['defesa' => 7, 'ataque' => 5]. Com vários, o sorteio equilibra cada um
 * deles ao mesmo tempo: a soma de defesa dos times fica parecida e a soma de
 * ataque também (e, por consequência, o total).
 *
 *  1. Roda várias tentativas aleatórias. Cada uma começa com uma divisão ao
 *     acaso e melhora trocando jogadores entre times enquanto a diferença
 *     entre o time mais forte e o mais fraco diminuir.
 *  2. Entre as tentativas, guarda as que ficaram (quase) tão equilibradas
 *     quanto a melhor e sorteia uma delas. O resultado é balanceado, mas muda
 *     a cada sorteio.
 *
 * Classe pura (sem banco): recebe [id => score] ou [id => [nível, nível…]] e devolve listas de ids.
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
     * @param array    $scores   [idJogador => score] ou [idJogador => ['defesa' => x, 'ataque' => y]]
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

        $scores = self::vetores($scores);
        $ids = array_keys($scores);
        $dimensoes = count(reset($scores));
        $zero = array_fill(0, $dimensoes, 0.0);

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
                $somas[$t] = $zero;
                foreach ($times[$t] as $id) {
                    $somas[$t] = self::soma($somas[$t], $scores[$id]);
                }
                $inicio += $capacidade[$t];
            }

            $this->melhorar($times, $somas, $scores);

            $diferenca = $this->objetivo($somas)[0];
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
                return array_sum($scores[$b]) <=> array_sum($scores[$a]);
            });
            $resultado[] = array_values($time);
        }

        return $resultado;
    }

    /**
     * Diferença entre o time mais forte e o mais fraco. Com vários níveis,
     * soma a diferença de cada um (ex.: diferença de defesa + diferença de ataque).
     */
    public static function diferenca(array $times, array $scores)
    {
        $scores = self::vetores($scores);
        $dimensoes = count(reset($scores));
        $total = 0.0;
        for ($d = 0; $d < $dimensoes; $d++) {
            $somas = [];
            foreach ($times as $time) {
                $soma = 0.0;
                foreach ($time as $id) {
                    $soma += $scores[$id][$d];
                }
                $somas[] = $soma;
            }
            $total += max($somas) - min($somas);
        }

        return (float) $total;
    }

    /** Normaliza para [id => [nível, nível…]] (lista de floats, mesma quantidade para todos). */
    private static function vetores(array $scores)
    {
        $saida = [];
        foreach ($scores as $id => $valor) {
            $saida[$id] = is_array($valor) ? array_values(array_map('floatval', $valor)) : [(float) $valor];
        }

        return $saida;
    }

    private static function soma(array $a, array $b, $sinal = 1)
    {
        foreach ($b as $d => $v) {
            $a[$d] += $sinal * $v;
        }

        return $a;
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
                            $delta = self::soma($scores[$jogadorB], $scores[$jogadorA], -1);
                            if (max(array_map('abs', $delta)) < 1e-9) {
                                continue; // trocar iguais não muda nada
                            }

                            $novas = $somas;
                            $novas[$a] = self::soma($novas[$a], $delta);
                            $novas[$b] = self::soma($novas[$b], $delta, -1);

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
            $somas[$a] = self::soma($somas[$a], $delta);
            $somas[$b] = self::soma($somas[$b], $delta, -1);
        }
    }

    /**
     * [soma das diferenças máx-mín de cada nível, soma dos quadrados]
     * $somas: [time => [nível, nível…]]
     */
    private function objetivo(array $somas)
    {
        $diferenca = 0.0;
        $quadrados = 0.0;
        $dimensoes = count(reset($somas));
        for ($d = 0; $d < $dimensoes; $d++) {
            $coluna = array_column($somas, $d);
            $diferenca += max($coluna) - min($coluna);
            foreach ($coluna as $v) {
                $quadrados += $v * $v;
            }
        }

        return [$diferenca, $quadrados];
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
