<?php

namespace Model;

/**
 * Autenticação dos administradores.
 */
class Login extends Conect
{
    private $_conexao;

    public function __construct($sess_usuario = null)
    {
        $this->sess_usuario = $sess_usuario;
        $this->_conexao = $this->conecta();
    }

    /**
     * @return array Dados que vão para $_SESSION['sess_usuario'] (sem a senha).
     * @throws \DomainException
     */
    public function autenticar(array $dados)
    {
        $email = mb_strtolower(trim(isset($dados['email']) ? $dados['email'] : ''));
        $senha = isset($dados['senha']) ? (string) $dados['senha'] : '';

        $stmt = $this->_conexao->prepare('SELECT id, nome, email, senha FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // Mesma mensagem para e-mail inexistente e senha errada.
        $this->exige($usuario && password_verify($senha, $usuario['senha']), 'E-mail ou senha incorretos.');

        if (password_needs_rehash($usuario['senha'], PASSWORD_DEFAULT)) {
            $novo = $this->_conexao->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            $novo->execute([password_hash($senha, PASSWORD_DEFAULT), $usuario['id']]);
        }

        return $this->paraSessao($usuario);
    }

    /**
     * @return array Dados da sessão do novo usuário.
     * @throws \DomainException
     */
    public function cadastrar(array $dados)
    {
        $this->exige(
            strtolower((string) env('CADASTRO_ABERTO', 'true')) === 'true',
            'O cadastro de novos administradores está desativado.'
        );

        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $email = mb_strtolower(trim(isset($dados['email']) ? $dados['email'] : ''));
        $senha = isset($dados['senha']) ? (string) $dados['senha'] : '';

        $this->exige($nome !== '' && mb_strlen($nome) <= 120, 'Informe seu nome.');
        $this->exige(filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150, 'Informe um e-mail válido.');
        $this->exige(strlen($senha) >= 8, 'A senha precisa ter pelo menos 8 caracteres.');

        $stmt = $this->_conexao->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $this->exige(!$stmt->fetch(), 'Já existe uma conta com esse e-mail.');

        $stmt = $this->_conexao->prepare('INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)');
        $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);

        return $this->paraSessao([
            'id' => (int) $this->_conexao->lastInsertId(),
            'nome' => $nome,
            'email' => $email,
        ]);
    }

    private function paraSessao(array $usuario)
    {
        return [
            'id' => (int) $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
        ];
    }
}
