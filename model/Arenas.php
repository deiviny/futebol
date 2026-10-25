<?php

namespace Model;

/**
 * Cadastro público de arenas: qualquer administrador vê e usa todas,
 * mas só quem cadastrou pode editar ou excluir.
 */
class Arenas extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /** @return array[] */
    public function listing()
    {
        $stmt = $this->_conexao->prepare(
            "SELECT a.id, a.nome, a.valor, a.endereco, a.url_mapa,
                    (a.id_usuario = ?) AS minha, u.nome AS cadastrada_por
             FROM arenas a
             LEFT JOIN usuarios u ON u.id = a.id_usuario
             WHERE a.excluido = 'n'
             ORDER BY a.nome ASC"
        );
        $stmt->execute([$this->idUsuario()]);

        $lista = $stmt->fetchAll();
        foreach ($lista as &$arena) {
            $arena['id'] = (int) $arena['id'];
            $arena['valor'] = (float) $arena['valor'];
            $arena['minha'] = (bool) $arena['minha'];
        }

        return $lista;
    }

    /** @return int id da arena */
    public function salvar(array $dados)
    {
        $id = isset($dados['id']) ? (int) $dados['id'] : 0;
        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $endereco = trim(isset($dados['endereco']) ? $dados['endereco'] : '');
        $urlMapa = trim(isset($dados['url_mapa']) ? $dados['url_mapa'] : '');
        $valor = $this->decimal(isset($dados['valor']) ? $dados['valor'] : '0');

        $this->exige($nome !== '' && mb_strlen($nome) <= 120, 'Informe o nome da arena.');
        $this->exige($endereco !== '' && mb_strlen($endereco) <= 255, 'Informe o endereço da arena.');
        $this->exige($valor !== null && $valor >= 0 && $valor <= 99999999, 'Valor inválido.');

        if ($urlMapa !== '') {
            $this->exige(
                mb_strlen($urlMapa) <= 500 && preg_match('#^https?://#i', $urlMapa) && filter_var($urlMapa, FILTER_VALIDATE_URL),
                'O link do mapa precisa começar com http:// ou https://.'
            );
        }

        if ($id > 0) {
            $this->arenaDoUsuario($id);
            $stmt = $this->_conexao->prepare(
                'UPDATE arenas SET nome = ?, valor = ?, endereco = ?, url_mapa = ? WHERE id = ?'
            );
            $stmt->execute([$nome, $valor, $endereco, $urlMapa !== '' ? $urlMapa : null, $id]);

            return $id;
        }

        $stmt = $this->_conexao->prepare(
            'INSERT INTO arenas (id_usuario, nome, valor, endereco, url_mapa) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->idUsuario(), $nome, $valor, $endereco, $urlMapa !== '' ? $urlMapa : null]);

        return (int) $this->_conexao->lastInsertId();
    }

    public function excluir($id)
    {
        $this->arenaDoUsuario($id);

        $stmt = $this->_conexao->prepare("UPDATE arenas SET excluido = 's' WHERE id = ?");
        $stmt->execute([(int) $id]);

        // Grupos que usavam essa arena como padrão ficam sem arena padrão.
        $stmt = $this->_conexao->prepare('UPDATE grupos SET id_arena_padrao = NULL WHERE id_arena_padrao = ?');
        $stmt->execute([(int) $id]);
    }

    /** Arena que o usuário logado cadastrou (só ele edita). */
    private function arenaDoUsuario($id)
    {
        $stmt = $this->_conexao->prepare(
            "SELECT id FROM arenas WHERE id = ? AND id_usuario = ? AND excluido = 'n'"
        );
        $stmt->execute([(int) $id, $this->idUsuario()]);

        $this->exige($stmt->fetch(), 'Você só pode alterar arenas que você cadastrou.');
    }
}
