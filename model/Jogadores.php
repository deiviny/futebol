<?php

namespace Model;

/**
 * Jogadores de um grupo. O cadastro da pessoa (nome, WhatsApp...) é único por
 * WhatsApp e pode estar em vários grupos; score, "quem convidou" e situação
 * são do vínculo com cada grupo.
 */
class Jogadores extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /** @return array[] */
    public function listing($idGrupo)
    {
        $this->grupoDoUsuario($idGrupo);

        $stmt = $this->_conexao->prepare(
            'SELECT j.id, j.nome, j.whatsapp, j.instagram, j.data_nascimento,
                    gj.score, gj.ativo, gj.id_convidado_por, c.nome AS convidado_por
             FROM grupos_jogadores gj
             JOIN jogadores j ON j.id = gj.id_jogador
             LEFT JOIN jogadores c ON c.id = gj.id_convidado_por
             WHERE gj.id_grupo = ?
             ORDER BY gj.ativo DESC, j.nome ASC'
        );
        $stmt->execute([(int) $idGrupo]);

        $lista = $stmt->fetchAll();
        foreach ($lista as &$j) {
            $j['id'] = (int) $j['id'];
            $j['score'] = (float) $j['score'];
            $j['id_convidado_por'] = $j['id_convidado_por'] ? (int) $j['id_convidado_por'] : null;
        }

        return $lista;
    }

    /** @return int id do jogador */
    public function salvar(array $dados)
    {
        $idGrupo = isset($dados['id_grupo']) ? (int) $dados['id_grupo'] : 0;
        $this->grupoDoUsuario($idGrupo);

        $idJogador = isset($dados['id']) ? (int) $dados['id'] : 0;
        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $whatsapp = self::normalizaWhatsapp(isset($dados['whatsapp']) ? $dados['whatsapp'] : '');
        $instagram = ltrim(trim(isset($dados['instagram']) ? $dados['instagram'] : ''), '@');
        $nascimento = trim(isset($dados['data_nascimento']) ? $dados['data_nascimento'] : '');
        $score = $this->decimal(isset($dados['score']) ? $dados['score'] : '5');
        $ativo = (isset($dados['ativo']) && $dados['ativo'] === 'n') ? 'n' : 's';
        $idConvidou = isset($dados['id_convidado_por']) ? (int) $dados['id_convidado_por'] : 0;

        $this->exige($nome !== '' && mb_strlen($nome) <= 120, 'Informe o nome do jogador.');
        $this->exige($whatsapp !== null, 'WhatsApp inválido. Use DDD + número (ex.: 62 99999-8888).');
        $this->exige(mb_strlen($instagram) <= 60 && preg_match('/^[A-Za-z0-9._]*$/', $instagram), 'Instagram inválido.');
        $this->exige($score !== null && $score >= 0 && $score <= 10, 'O score vai de 0 a 10.');
        $score = round($score * 2) / 2; // passos de 0,5

        if ($nascimento !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $nascimento);
            $this->exige($d && $d->format('Y-m-d') === $nascimento && $nascimento <= date('Y-m-d'), 'Data de nascimento inválida.');
        }

        if ($idConvidou > 0) {
            $this->exige($idConvidou !== $idJogador, 'O jogador não pode convidar a si mesmo.');
            $this->jogadorDoGrupo($idGrupo, $idConvidou);
        }

        $this->_conexao->beginTransaction();
        try {
            if ($idJogador > 0) {
                // Edição: o jogador precisa estar neste grupo.
                $this->jogadorDoGrupo($idGrupo, $idJogador);
                $this->exige($this->semConflitoWhatsapp($idJogador, $whatsapp), 'Já existe outro jogador com esse WhatsApp.');
                $this->atualizaPessoa($idJogador, $nome, $whatsapp, $instagram, $nascimento);
            } else {
                $idJogador = $this->pessoaPorWhatsapp($whatsapp);

                if ($idJogador) {
                    // Pessoa já cadastrada (talvez em outro grupo): só vincula, sem sobrescrever dados dela.
                    $stmt = $this->_conexao->prepare('SELECT 1 FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ?');
                    $stmt->execute([$idGrupo, $idJogador]);
                    $this->exige(!$stmt->fetch(), 'Esse jogador já está neste grupo.');
                } else {
                    $stmt = $this->_conexao->prepare(
                        'INSERT INTO jogadores (nome, whatsapp, instagram, data_nascimento) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$nome, $whatsapp, $instagram !== '' ? $instagram : null, $nascimento !== '' ? $nascimento : null]);
                    $idJogador = (int) $this->_conexao->lastInsertId();
                }
            }

            $stmt = $this->_conexao->prepare(
                'INSERT INTO grupos_jogadores (id_grupo, id_jogador, score, id_convidado_por, ativo)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), id_convidado_por = VALUES(id_convidado_por), ativo = VALUES(ativo)'
            );
            $stmt->execute([$idGrupo, $idJogador, $score, $idConvidou > 0 ? $idConvidou : null, $ativo]);

            $this->_conexao->commit();
        } catch (\Exception $e) {
            $this->_conexao->rollBack();
            throw $e;
        }

        return $idJogador;
    }

    /**
     * Tira o jogador do grupo. Com histórico (presença em jogos), apenas inativa
     * para não perder estatísticas e cobranças.
     *
     * @return string 'removido' | 'inativado'
     */
    public function remover($idGrupo, $idJogador)
    {
        $this->grupoDoUsuario($idGrupo);
        $this->jogadorDoGrupo($idGrupo, $idJogador);

        $stmt = $this->_conexao->prepare(
            'SELECT 1 FROM jogos_presencas p JOIN jogos jo ON jo.id = p.id_jogo
             WHERE jo.id_grupo = ? AND p.id_jogador = ? LIMIT 1'
        );
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);

        if ($stmt->fetch()) {
            $stmt = $this->_conexao->prepare(
                "UPDATE grupos_jogadores SET ativo = 'n' WHERE id_grupo = ? AND id_jogador = ?"
            );
            $stmt->execute([(int) $idGrupo, (int) $idJogador]);

            return 'inativado';
        }

        $stmt = $this->_conexao->prepare('DELETE FROM grupos_jogadores WHERE id_grupo = ? AND id_jogador = ?');
        $stmt->execute([(int) $idGrupo, (int) $idJogador]);

        return 'removido';
    }

    /** Só dígitos com DDI. null se inválido. "62 99999-8888" => "5562999998888". */
    public static function normalizaWhatsapp($texto)
    {
        $n = soNumero($texto);
        if ($n === '') {
            return null;
        }
        if (strlen($n) <= 11) {
            $n = '55' . $n;
        }

        return (strlen($n) >= 12 && strlen($n) <= 15) ? $n : null;
    }

    /** id da pessoa com esse WhatsApp (qualquer grupo) ou 0. */
    public function pessoaPorWhatsapp($whatsapp)
    {
        $stmt = $this->_conexao->prepare('SELECT id FROM jogadores WHERE whatsapp = ?');
        $stmt->execute([$whatsapp]);
        $linha = $stmt->fetch();

        return $linha ? (int) $linha['id'] : 0;
    }

    private function semConflitoWhatsapp($idJogador, $whatsapp)
    {
        $existente = $this->pessoaPorWhatsapp($whatsapp);

        return $existente === 0 || $existente === (int) $idJogador;
    }

    /**
     * Dados pessoais são compartilhados entre grupos. Só altera se a pessoa não
     * estiver em grupos que o usuário não administra — senão cada admin só mexe no
     * que é do vínculo dele (score, convite, situação).
     */
    private function atualizaPessoa($idJogador, $nome, $whatsapp, $instagram, $nascimento)
    {
        $stmt = $this->_conexao->prepare(
            'SELECT 1 FROM grupos_jogadores gj JOIN grupos g ON g.id = gj.id_grupo
             WHERE gj.id_jogador = ? AND NOT ' . $this->sqlAdminDoGrupo('g') . ' LIMIT 1'
        );
        $stmt->execute([(int) $idJogador, $this->idUsuario(), $this->idUsuario()]);

        if ($stmt->fetch()) {
            return;
        }

        $stmt = $this->_conexao->prepare(
            'UPDATE jogadores SET nome = ?, whatsapp = ?, instagram = ?, data_nascimento = ? WHERE id = ?'
        );
        $stmt->execute([
            $nome, $whatsapp, $instagram !== '' ? $instagram : null, $nascimento !== '' ? $nascimento : null, (int) $idJogador,
        ]);
    }
}
