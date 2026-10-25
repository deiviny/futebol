<?php

namespace Model;

/**
 * Gera os jogos dos grupos com "jogo fixo semanal" para as próximas semanas.
 *
 * É chamado sozinho quando o administrador abre o sistema (não depende de
 * cron) e também pode rodar por agendador: php cron/gerar_jogos.php
 */
class Recorrentes extends Conect
{
    private $_conexao;

    /** @param array|null $sess_usuario Sem usuário = todos os grupos (uso do cron). */
    public function __construct($sess_usuario = null)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /**
     * Cria os jogos que faltam. Dias que já têm jogo (inclusive cancelado) não são recriados.
     *
     * @return int quantidade de jogos criados
     */
    public function gerar($semanas = 4)
    {
        $sql = "SELECT g.* FROM grupos g
                WHERE g.recorrente = 's' AND g.excluido = 'n' AND g.dia_semana IS NOT NULL AND g.horario IS NOT NULL";
        $params = [];

        if ($this->sess_usuario !== null) {
            $sql .= ' AND ' . $this->sqlAdminDoGrupo('g');
            $params[] = $this->idUsuario();
            $params[] = $this->idUsuario();
        }

        $stmt = $this->_conexao->prepare($sql);
        $stmt->execute($params);
        $grupos = $stmt->fetchAll();

        $existe = $this->_conexao->prepare(
            'SELECT 1 FROM jogos WHERE id_grupo = ? AND data_hora >= ? AND data_hora < ? LIMIT 1'
        );
        $insere = $this->_conexao->prepare(
            'INSERT IGNORE INTO jogos (id_grupo, id_arena, data_hora, jogadores_por_time, token) VALUES (?, ?, ?, ?, ?)'
        );

        $agora = date('Y-m-d H:i:s');
        $criados = 0;

        foreach ($grupos as $g) {
            for ($dia = 0; $dia < $semanas * 7; $dia++) {
                $data = date('Y-m-d', strtotime("+{$dia} days"));
                if ((int) date('w', strtotime($data)) !== (int) $g['dia_semana']) {
                    continue;
                }

                $dataHora = $data . ' ' . substr($g['horario'], 0, 5) . ':00';
                if ($dataHora <= $agora) {
                    continue;
                }

                $existe->execute([(int) $g['id'], $data . ' 00:00:00', date('Y-m-d', strtotime($data . ' +1 day')) . ' 00:00:00']);
                if ($existe->fetch()) {
                    continue;
                }

                $insere->execute([
                    (int) $g['id'], $g['id_arena_padrao'] ?: null, $dataHora,
                    (int) $g['jogadores_por_time'], bin2hex(random_bytes(16)),
                ]);
                $criados += $insere->rowCount();
            }
        }

        return $criados;
    }
}
