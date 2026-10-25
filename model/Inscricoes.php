<?php

namespace Model;

/**
 * Link de inscrição do grupo: o jogador abre, preenche os dados e fica pendente
 * (ativo = 'n', pendente = 's') até um administrador aprovar ou recusar.
 */
class Inscricoes extends Conect
{
    private $_conexao;

    /** @param array|null $sess_usuario Sem usuário = página pública. */
    public function __construct($sess_usuario = null)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    // ------------------------------------------------------------ administrador

    /** Troca o token: o link antigo para de funcionar. @return string link novo */
    public function novoLink($idGrupo)
    {
        $this->grupoDoUsuario($idGrupo);

        $token = self::novoToken();
        $stmt = $this->_conexao->prepare('UPDATE grupos SET convite_token = ? WHERE id = ?');
        $stmt->execute([$token, (int) $idGrupo]);

        return self::link($token);
    }

    public function abrir($idGrupo, $aberta)
    {
        $this->grupoDoUsuario($idGrupo);

        $stmt = $this->_conexao->prepare('UPDATE grupos SET inscricao_aberta = ? WHERE id = ?');
        $stmt->execute([$aberta ? 's' : 'n', (int) $idGrupo]);
    }

    public function aprovar($idGrupo, $idJogador)
    {
        $this->pendenteDoGrupo($idGrupo, $idJogador);

        $stmt = $this->_conexao->prepare(
            "UPDATE grupos_jogadores SET ativo = 's', pendente = 'n' WHERE id_grupo = ? AND id_jogador = ?"
        );
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);
    }

    /** Recusa o pedido: some o vínculo com o grupo (o cadastro da pessoa fica, pode estar em outros grupos). */
    public function recusar($idGrupo, $idJogador)
    {
        $this->pendenteDoGrupo($idGrupo, $idJogador);

        $stmt = $this->_conexao->prepare(
            "DELETE FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ? AND pendente = 's'"
        );
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);
    }

    public static function novoToken()
    {
        return bin2hex(random_bytes(16));
    }

    public static function link($token)
    {
        return $token ? baseUrl() . '/?tela=inscricao&g=' . $token : null;
    }

    // ---------------------------------------------------------------- público

    /** Dados mostrados na página do link. Sem dados de jogadores. */
    public function publicoInfo($token)
    {
        $g = $this->grupoPorToken($token);

        return [
            'grupo_nome' => $g['nome'],
            'aberta' => $g['inscricao_aberta'] === 's',
            'resumo_fixo' => $g['recorrente'] === 's' && $g['dia_semana'] !== null && $g['horario']
                ? 'Toda ' . diaSemanaNome($g['dia_semana']) . ', ' . substr($g['horario'], 0, 5)
                : null,
        ];
    }

    /**
     * @return array ['status' => 'enviado'|'ja_pendente'|'ja_membro'|'inativo', 'nome' => ...]
     */
    public function publicoInscrever($token, array $dados)
    {
        $g = $this->grupoPorToken($token);
        $this->exige($g['inscricao_aberta'] === 's', 'As inscrições deste grupo estão fechadas.');

        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $whatsapp = Jogadores::normalizaWhatsapp(isset($dados['whatsapp']) ? $dados['whatsapp'] : '');
        $instagram = ltrim(trim(isset($dados['instagram']) ? $dados['instagram'] : ''), '@');
        $nascimento = trim(isset($dados['data_nascimento']) ? $dados['data_nascimento'] : '');

        $this->exige($nome !== '' && mb_strlen($nome) <= 120, 'Informe seu nome.');
        $this->exige($whatsapp !== null, 'WhatsApp inválido. Use DDD + número (ex.: 62 99999-8888).');
        $this->exige(mb_strlen($instagram) <= 60 && preg_match('/^[A-Za-z0-9._]*$/', $instagram), 'Instagram inválido.');
        if ($nascimento !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $nascimento);
            $this->exige($d && $d->format('Y-m-d') === $nascimento && $nascimento <= date('Y-m-d'), 'Data de nascimento inválida.');
        }

        $idGrupo = (int) $g['id'];

        $this->_conexao->beginTransaction();
        try {
            $stmt = $this->_conexao->prepare('SELECT id, nome FROM jogadores WHERE whatsapp = ?');
            $stmt->execute([$whatsapp]);
            $pessoa = $stmt->fetch();

            if ($pessoa) {
                // Já tem cadastro (talvez em outro grupo): não sobrescreve os dados de ninguém pelo link público.
                $idJogador = (int) $pessoa['id'];
                $nome = $pessoa['nome'];

                $stmt = $this->_conexao->prepare('SELECT ativo, pendente FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ?');
                $stmt->execute([$idGrupo, $idJogador]);
                $vinculo = $stmt->fetch();
                if ($vinculo) {
                    $this->_conexao->commit();
                    $status = $vinculo['pendente'] === 's' ? 'ja_pendente' : ($vinculo['ativo'] === 's' ? 'ja_membro' : 'inativo');

                    return ['status' => $status, 'nome' => $nome];
                }
            } else {
                $stmt = $this->_conexao->prepare(
                    'INSERT INTO jogadores (nome, whatsapp, instagram, data_nascimento) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$nome, $whatsapp, $instagram !== '' ? $instagram : null, $nascimento !== '' ? $nascimento : null]);
                $idJogador = (int) $this->_conexao->lastInsertId();
            }

            $stmt = $this->_conexao->prepare(
                "INSERT INTO grupos_jogadores (id_grupo, id_jogador, ativo, pendente) VALUES (?, ?, 'n', 's')"
            );
            $stmt->execute([$idGrupo, $idJogador]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return ['status' => 'enviado', 'nome' => $nome];
    }

    // ------------------------------------------------------------ auxiliares

    private function grupoPorToken($token)
    {
        $this->exige(preg_match('/^[0-9a-f]{32}$/', (string) $token), 'Link inválido.');

        $stmt = $this->_conexao->prepare("SELECT * FROM grupos WHERE convite_token = ? AND excluido = 'n'");
        $stmt->execute([$token]);
        $grupo = $stmt->fetch();

        $this->exige($grupo, 'Link inválido ou trocado pelo administrador. Peça o link novo.');

        return $grupo;
    }

    private function pendenteDoGrupo($idGrupo, $idJogador)
    {
        $this->grupoDoUsuario($idGrupo);

        $stmt = $this->_conexao->prepare(
            "SELECT 1 FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ? AND pendente = 's'"
        );
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);

        $this->exige($stmt->fetch(), 'Pedido não encontrado (talvez outro admin já respondeu).');
    }
}
