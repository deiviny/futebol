<?php

namespace Model;

/**
 * Classe base dos models: conexão PDO e regras comuns de segurança (multi-tenant).
 *
 * Multi-tenant neste sistema: cada administrador (usuarios.id) só enxerga os
 * próprios grupos e tudo que está dentro deles (jogadores do grupo, jogos,
 * presenças, times e pagamentos). Arenas são a exceção: são públicas para uso,
 * mas só o criador edita.
 */
class Conect
{
    protected $sess_usuario;

    private static $_pdo = null;

    protected function conecta()
    {
        if (self::$_pdo instanceof \PDO) {
            return self::$_pdo;
        }

        try {
            $dsn = 'mysql:host=' . SERVER . ';port=' . PORTA . ';dbname=' . BANCO . ';charset=utf8mb4';

            self::$_pdo = new \PDO($dsn, USER, PASS, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return self::$_pdo;
        } catch (\PDOException $e) {
            error_log('Erro de conexão: ' . $e->getMessage());
            throw new \Exception('Erro ao conectar ao banco de dados');
        }
    }

    /** Id do administrador logado (tenant). */
    protected function idUsuario()
    {
        if (empty($this->sess_usuario['id'])) {
            throw new \DomainException('Sessão expirada. Entre novamente.');
        }

        return (int) $this->sess_usuario['id'];
    }

    /** Lança DomainException (mensagem mostrada ao usuário) quando a condição é falsa. */
    protected function exige($condicao, $mensagem)
    {
        if (!$condicao) {
            throw new \DomainException($mensagem);
        }
    }

    /** Grupo do administrador logado, ou erro. ⚠️ Multi-tenant: sempre filtra por id_usuario. */
    protected function grupoDoUsuario($idGrupo)
    {
        $stmt = $this->conecta()->prepare(
            "SELECT * FROM grupos WHERE id = ? AND id_usuario = ? AND excluido = 'n'"
        );
        $stmt->execute([(int) $idGrupo, $this->idUsuario()]);
        $grupo = $stmt->fetch();

        $this->exige($grupo, 'Grupo não encontrado.');

        return $grupo;
    }

    /**
     * Jogo (com dados do grupo e da arena) do administrador logado, ou erro.
     * ⚠️ Multi-tenant: o jogo só vale se o grupo dele pertencer ao usuário.
     */
    protected function jogoDoUsuario($idJogo)
    {
        $stmt = $this->conecta()->prepare(
            "SELECT jo.*, g.nome AS grupo_nome, g.pix_chave, g.pix_nome, g.pix_cidade,
                    g.colete1_nome, g.colete1_cor, g.colete2_nome, g.colete2_cor,
                    a.nome AS arena_nome, a.endereco AS arena_endereco, a.url_mapa AS arena_url_mapa, a.valor AS arena_valor
             FROM jogos jo
             JOIN grupos g ON g.id = jo.id_grupo AND g.id_usuario = ? AND g.excluido = 'n'
             LEFT JOIN arenas a ON a.id = jo.id_arena
             WHERE jo.id = ?"
        );
        $stmt->execute([$this->idUsuario(), (int) $idJogo]);
        $jogo = $stmt->fetch();

        $this->exige($jogo, 'Jogo não encontrado.');

        return $jogo;
    }

    /** O jogador precisa fazer parte do grupo. */
    protected function jogadorDoGrupo($idGrupo, $idJogador)
    {
        $stmt = $this->conecta()->prepare(
            'SELECT j.*, gj.score, gj.ativo
             FROM grupos_jogadores gj
             JOIN jogadores j ON j.id = gj.id_jogador
             WHERE gj.id_grupo = ? AND gj.id_jogador = ?'
        );
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);
        $jogador = $stmt->fetch();

        $this->exige($jogador, 'Jogador não encontrado neste grupo.');

        return $jogador;
    }

    /** O jogo ainda está aberto (não finalizado nem cancelado)? */
    protected function exigeJogoAberto(array $jogo)
    {
        $this->exige(
            in_array($jogo['situacao'], ['agendado', 'em_andamento'], true),
            'Este jogo já foi encerrado ou cancelado.'
        );
    }

    /** Número decimal vindo do formulário ("15", "15.5" ou "15,5"). */
    protected function decimal($valor)
    {
        $valor = str_replace(',', '.', trim((string) $valor));

        return is_numeric($valor) ? (float) $valor : null;
    }

    /** Placeholders "?,?,?" para IN (...). */
    protected function placeholders(array $itens)
    {
        return implode(',', array_fill(0, count($itens), '?'));
    }
}
