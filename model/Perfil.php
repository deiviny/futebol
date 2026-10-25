<?php

namespace Model;

/**
 * "Meus dados": o administrador logado vê e altera a própria conta.
 */
class Perfil extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    public function dados()
    {
        $stmt = $this->_conexao->prepare('SELECT id, nome, email, telefone, criado_em FROM usuarios WHERE id = ?');
        $stmt->execute([$this->idUsuario()]);
        $u = $stmt->fetch();
        $this->exige($u, 'Conta não encontrada. Entre novamente.');

        // Grupos que ele administra: os que criou e os em que foi colocado como admin.
        $stmt = $this->_conexao->prepare(
            "SELECT g.id, g.nome, (g.id_usuario = ?) AS dono
             FROM grupos g
             WHERE " . $this->sqlAdminDoGrupo('g') . " AND g.excluido = 'n'
             ORDER BY g.nome ASC"
        );
        $stmt->execute([$this->idUsuario(), $this->idUsuario(), $this->idUsuario()]);
        $grupos = [];
        foreach ($stmt->fetchAll() as $g) {
            $grupos[] = ['id' => (int) $g['id'], 'nome' => $g['nome'], 'dono' => (bool) $g['dono']];
        }

        return [
            'nome' => $u['nome'],
            'email' => $u['email'],
            'telefone' => $u['telefone'],
            'desde' => date('d/m/Y', strtotime($u['criado_em'])),
            'grupos' => $grupos,
        ];
    }

    /** @return array dados novos da sessão */
    public function salvar(array $dados)
    {
        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $email = mb_strtolower(trim(isset($dados['email']) ? $dados['email'] : ''));
        $telefone = Jogadores::normalizaWhatsapp(isset($dados['telefone']) ? $dados['telefone'] : '');

        $this->exige($nome !== '' && mb_strlen($nome) <= 120, 'Informe seu nome.');
        $this->exige(filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150, 'Informe um e-mail válido.');
        $this->exige($telefone !== null, 'Informe seu WhatsApp com DDD (ex.: 62 99999-8888).');

        $stmt = $this->_conexao->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $this->idUsuario()]);
        $this->exige(!$stmt->fetch(), 'Já existe outra conta com esse e-mail.');

        $stmt = $this->_conexao->prepare('SELECT id FROM usuarios WHERE telefone = ? AND id <> ?');
        $stmt->execute([$telefone, $this->idUsuario()]);
        $this->exige(!$stmt->fetch(), 'Já existe outra conta com esse WhatsApp.');

        $stmt = $this->_conexao->prepare('UPDATE usuarios SET nome = ?, email = ?, telefone = ? WHERE id = ?');
        $stmt->execute([$nome, $email, $telefone, $this->idUsuario()]);

        return ['id' => $this->idUsuario(), 'nome' => $nome, 'email' => $email];
    }

    public function trocarSenha(array $dados)
    {
        $atual = isset($dados['senha_atual']) ? (string) $dados['senha_atual'] : '';
        $nova = isset($dados['senha_nova']) ? (string) $dados['senha_nova'] : '';

        $stmt = $this->_conexao->prepare('SELECT senha FROM usuarios WHERE id = ?');
        $stmt->execute([$this->idUsuario()]);
        $hash = $stmt->fetchColumn();

        $this->exige($hash && password_verify($atual, $hash), 'Senha atual incorreta.');
        $this->exige(strlen($nova) >= 8, 'A nova senha precisa ter pelo menos 8 caracteres.');

        $stmt = $this->_conexao->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->execute([password_hash($nova, PASSWORD_DEFAULT), $this->idUsuario()]);
    }
}
