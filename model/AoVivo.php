<?php

namespace Model;

/**
 * Tela do jogador (link do jogo ao vivo): o jogador entra só com o telefone, acompanha a
 * partida, o próximo time e a lista de espera, e marca gols e assistências.
 *
 * Login: o telefone precisa ser de um jogador ativo (e aprovado) do grupo do jogo. O token
 * fica num cookie do celular por 180 dias (renovado a cada uso); o banco guarda só o sha256.
 * O jogador não administra nada: sortear, encerrar partida, par ou ímpar e fechar o jogo
 * continuam só na tela do administrador. O nível (score, defesa, ataque) nunca sai daqui.
 */
class AoVivo extends Conect
{
    const COOKIE = 'futebol_jogador';
    const DIAS = 180;

    private $_conexao;

    public function __construct($sess_usuario = null)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    // ---------------------------------------------------------------- login

    /**
     * Entra com o telefone. Devolve o token (para o cookie) e o jogador.
     *
     * @return array ['token' => string, 'jogador' => array]
     */
    public function entrar($tokenJogo, $telefone)
    {
        $jogo = $this->jogoPorToken($tokenJogo);

        $numero = Jogadores::normalizaWhatsapp($telefone);
        $this->exige($numero !== null, 'Telefone inválido. Use DDD + número (ex.: 62 99999-8888).');

        $stmt = $this->_conexao->prepare(
            'SELECT j.id, j.nome, gj.ativo, gj.pendente FROM jogadores j
             JOIN grupos_jogadores gj ON gj.id_jogador = j.id AND gj.id_grupo = ?
             WHERE j.whatsapp = ?'
        );
        $stmt->execute([(int) $jogo['id_grupo'], $numero]);
        $membro = $stmt->fetch();

        $this->exige($membro, 'Não achamos esse telefone no grupo. Use o número que o administrador cadastrou para você.');
        $this->exige($membro['pendente'] === 'n', 'Seu pedido para entrar no grupo ainda aguarda a aprovação do administrador.');
        $this->exige($membro['ativo'] === 's', 'Seu cadastro está inativo neste grupo. Fale com o administrador.');

        $token = bin2hex(random_bytes(32));
        $stmt = $this->_conexao->prepare(
            'INSERT INTO jogadores_sessoes (id_jogador, token_hash, usado_em, expira_em) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $membro['id'], hash('sha256', $token), date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+' . self::DIAS . ' days')),
        ]);

        // Limpa sessões vencidas (barato e raro o bastante para fazer aqui).
        $this->_conexao->exec('DELETE FROM jogadores_sessoes WHERE expira_em < NOW()');

        return ['token' => $token, 'jogador' => ['id' => (int) $membro['id'], 'nome' => $membro['nome']]];
    }

    /** Jogador do token do cookie (ou null). Renova a validade a cada uso. */
    public function jogadorDoToken($token)
    {
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $stmt = $this->_conexao->prepare(
            'SELECT s.id, s.usado_em, j.id AS id_jogador, j.nome
             FROM jogadores_sessoes s JOIN jogadores j ON j.id = s.id_jogador
             WHERE s.token_hash = ? AND s.expira_em >= NOW()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $s = $stmt->fetch();
        if (!$s) {
            return null;
        }

        // Renova no máximo uma vez por hora (a tela consulta a cada poucos segundos).
        if (!$s['usado_em'] || strtotime($s['usado_em']) < time() - 3600) {
            $stmt = $this->_conexao->prepare('UPDATE jogadores_sessoes SET usado_em = ?, expira_em = ? WHERE id = ?');
            $stmt->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+' . self::DIAS . ' days')), (int) $s['id']]);
        }

        return ['id' => (int) $s['id_jogador'], 'nome' => $s['nome']];
    }

    public function sair($token)
    {
        if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token)) {
            $stmt = $this->_conexao->prepare('DELETE FROM jogadores_sessoes WHERE token_hash = ?');
            $stmt->execute([hash('sha256', $token)]);
        }
    }

    // ---------------------------------------------------------------- tela

    /** Cabeçalho do jogo, para a tela de entrar (sem login). */
    public function cabecalho($tokenJogo)
    {
        $jogo = $this->jogoPorToken($tokenJogo);

        return [
            'grupo_nome' => $jogo['grupo_nome'],
            'data_fmt' => dataHoraBr($jogo['data_hora']),
            'situacao' => $jogo['situacao'],
            'arena_nome' => $jogo['arena_nome'],
        ];
    }

    /** Tudo o que o jogador logado vê: partida ao vivo, próximo time, lista de espera e o histórico da noite. */
    public function tela($tokenJogo, array $jogador)
    {
        $jogo = $this->jogoPorToken($tokenJogo);
        $this->exigeMembro($jogo, $jogador);

        $painel = (new Partidas(null))->painel($jogo);
        $proxima = $painel['proxima'];

        $id = (int) $jogo['id'];
        $stmt = $this->_conexao->prepare('SELECT COUNT(*) FROM jogos_times WHERE id_jogo = ?');
        $stmt->execute([$id]);
        $sorteado = (int) $stmt->fetchColumn() > 0;

        $stmt = $this->_conexao->prepare(
            "SELECT j.id AS id_jogador, j.nome, p.chegada_em IS NOT NULL AS chegou
             FROM jogos_presencas p JOIN jogadores j ON j.id = p.id_jogador
             WHERE p.id_jogo = ? AND p.resposta = 'confirmado'
             ORDER BY (p.chegada_em IS NULL), p.chegada_em ASC, p.confirmado_em ASC, j.nome ASC"
        );
        $stmt->execute([$id]);
        $confirmados = [];
        foreach ($stmt->fetchAll() as $c) {
            $confirmados[] = ['id_jogador' => (int) $c['id_jogador'], 'nome' => $c['nome'], 'chegou' => (bool) $c['chegou']];
        }

        $dados = [
            'grupo_nome' => $jogo['grupo_nome'],
            'data_fmt' => dataHoraBr($jogo['data_hora']),
            'situacao' => $jogo['situacao'],
            'aberto' => in_array($jogo['situacao'], ['agendado', 'em_andamento'], true),
            'arena_nome' => $jogo['arena_nome'],
            'jogadores_por_time' => (int) $jogo['jogadores_por_time'],
            'sorteado' => $sorteado,
            'coletes' => $painel['coletes'],
            'partida_atual' => $painel['partida_atual'],
            'proxima' => $proxima,
            'fila' => $painel['fila'],
            'historico' => array_slice($painel['historico'], 0, 10),
            'total_partidas' => count($painel['historico']),
            'artilheiros' => $painel['artilheiros'],
            'confirmados' => $confirmados,
            'eu' => $jogador,
            'admin_url' => $this->adminDoGrupo($jogo) ? '?tela=jogo&id=' . $id : null,
        ];

        return self::semNivel($dados);
    }

    /** Gol marcado pelo jogador logado. */
    public function gol($tokenJogo, array $jogador, array $dados)
    {
        $jogo = $this->jogoPorToken($tokenJogo);
        $this->exigeMembro($jogo, $jogador);
        $this->exige($jogo['situacao'] === 'em_andamento', 'O jogo não está em andamento.');

        (new Partidas(null))->golPeloJogador(
            $jogo,
            isset($dados['id_partida']) ? (int) $dados['id_partida'] : 0,
            isset($dados['id_jogador']) ? (int) $dados['id_jogador'] : 0,
            isset($dados['lado']) ? (string) $dados['lado'] : '',
            !empty($dados['contra']) && $dados['contra'] !== '0',
            isset($dados['id_assistencia']) ? (int) $dados['id_assistencia'] : 0,
            (int) $jogador['id']
        );
    }

    /** O jogador desfaz um gol que ele mesmo marcou. */
    public function desfazerGol($tokenJogo, array $jogador, $idGol)
    {
        $jogo = $this->jogoPorToken($tokenJogo);
        $this->exigeMembro($jogo, $jogador);

        (new Partidas(null))->desfazerGolDoJogador($jogo, $idGol, (int) $jogador['id']);
    }

    // ---------------------------------------------------------------- auxiliares

    /** Jogo do link, com os dados que o painel de partidas precisa (coletes, jogadores por time). */
    private function jogoPorToken($token)
    {
        $this->exige(preg_match('/^[0-9a-f]{32}$/', (string) $token), 'Link inválido.');

        $stmt = $this->_conexao->prepare(
            "SELECT jo.*, g.nome AS grupo_nome, g.id_usuario AS grupo_dono,
                    g.colete1_nome, g.colete1_cor, g.colete2_nome, g.colete2_cor,
                    a.nome AS arena_nome
             FROM jogos jo
             JOIN grupos g ON g.id = jo.id_grupo AND g.excluido = 'n'
             LEFT JOIN arenas a ON a.id = jo.id_arena
             WHERE jo.token = ?"
        );
        $stmt->execute([(string) $token]);
        $jogo = $stmt->fetch();

        $this->exige($jogo, 'Link inválido ou jogo não encontrado.');

        return $jogo;
    }

    /** O jogador logado continua sendo do grupo (ativo e aprovado)? */
    private function exigeMembro(array $jogo, array $jogador)
    {
        $stmt = $this->_conexao->prepare(
            "SELECT 1 FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ? AND ativo = 's' AND pendente = 'n'"
        );
        $stmt->execute([(int) $jogo['id_grupo'], (int) $jogador['id']]);
        if (!$stmt->fetch()) {
            // Código 401: a tela volta para o login.
            throw new \DomainException('Você não faz parte deste grupo (ou seu cadastro está inativo). Entre com outro telefone.', 401);
        }
    }

    /** O administrador logado neste navegador administra o grupo do jogo? (só para mostrar o atalho da tela dele) */
    private function adminDoGrupo(array $jogo)
    {
        if (empty($this->sess_usuario['id'])) {
            return false;
        }
        $stmt = $this->_conexao->prepare(
            "SELECT 1 FROM grupos g WHERE g.id = ? AND " . $this->sqlAdminDoGrupo('g')
        );
        $stmt->execute([(int) $jogo['id_grupo'], $this->idUsuario(), $this->idUsuario()]);

        return (bool) $stmt->fetch();
    }

    /**
     * Tira o nível dos jogadores de tudo o que vai para a tela do jogador: o score de cada um
     * e a soma dos times (a "soma" do par ou ímpar, que não tem jogadores, fica).
     */
    private static function semNivel($valor)
    {
        if (!is_array($valor)) {
            return $valor;
        }
        $limpo = [];
        foreach ($valor as $k => $v) {
            if ($k === 'score' || $k === 'defesa' || $k === 'ataque' || ($k === 'soma' && isset($valor['jogadores']))) {
                continue;
            }
            $limpo[$k] = self::semNivel($v);
        }

        return $limpo;
    }
}

