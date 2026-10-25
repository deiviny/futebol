<?php

namespace Model;

/**
 * Grupos de futebol que o administrador logado administra (ex.: "Futebol de sexta").
 * Quem cria o grupo é o dono: só ele muda a lista de administradores e exclui o grupo.
 */
class Grupos extends Conect
{
    /** Coletes que um grupo novo recebe (o pessoal costuma ter dois, de cores diferentes). */
    const COLETES_PADRAO = [['Laranja', '#FF9500'], ['Azul', '#007AFF']];

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
            "SELECT g.id, g.nome, g.jogadores_por_time, g.recorrente, g.dia_semana, g.horario,
                    (SELECT COUNT(*) FROM grupos_jogadores gj WHERE gj.id_grupo = g.id AND gj.ativo = 's') AS total_jogadores,
                    (SELECT MIN(jo.data_hora) FROM jogos jo
                      WHERE jo.id_grupo = g.id AND jo.situacao IN ('agendado','em_andamento')
                        AND jo.data_hora >= ?) AS proximo_jogo
             FROM grupos g
             WHERE " . $this->sqlAdminDoGrupo('g') . " AND g.excluido = 'n'
             ORDER BY g.nome ASC"
        );
        $stmt->execute([date('Y-m-d 00:00:00'), $this->idUsuario(), $this->idUsuario()]);

        $lista = $stmt->fetchAll();
        foreach ($lista as &$g) {
            $g['id'] = (int) $g['id'];
            $g['total_jogadores'] = (int) $g['total_jogadores'];
            $g['proximo_jogo_fmt'] = $g['proximo_jogo'] ? dataHoraBr($g['proximo_jogo']) : null;
            $g['resumo_fixo'] = $this->resumoFixo($g);
        }

        return $lista;
    }

    /** Grupo completo para a tela de detalhe/edição. */
    public function buscar($id)
    {
        $g = $this->grupoDoUsuario($id);

        $arena = null;
        if ($g['id_arena_padrao']) {
            $stmt = $this->_conexao->prepare("SELECT id, nome, valor FROM arenas WHERE id = ? AND excluido = 'n'");
            $stmt->execute([(int) $g['id_arena_padrao']]);
            $arena = $stmt->fetch() ?: null;
        }

        return [
            'id' => (int) $g['id'],
            'nome' => $g['nome'],
            'jogadores_por_time' => (int) $g['jogadores_por_time'],
            'recorrente' => $g['recorrente'],
            'dia_semana' => $g['dia_semana'] === null ? null : (int) $g['dia_semana'],
            'horario' => $g['horario'] ? substr($g['horario'], 0, 5) : null,
            'id_arena_padrao' => $g['id_arena_padrao'] ? (int) $g['id_arena_padrao'] : null,
            'arena_padrao' => $arena,
            'pix_tipo' => $g['pix_tipo'],
            'pix_chave' => $g['pix_chave'],
            'pix_nome' => $g['pix_nome'],
            'pix_cidade' => $g['pix_cidade'],
            'coletes' => self::coletes($g),
            'resumo_fixo' => $this->resumoFixo($g),
            'dono' => (bool) $g['dono'],
            'admins' => $this->admins($g),
        ];
    }

    /**
     * Dono e administradores convidados. "nome" vem da conta de quem já se cadastrou
     * com aquele WhatsApp (null = ainda não criou a conta).
     */
    private function admins(array $g)
    {
        $stmt = $this->_conexao->prepare('SELECT nome, telefone FROM usuarios WHERE id = ?');
        $stmt->execute([(int) $g['id_usuario']]);
        $dono = $stmt->fetch();

        $lista = [['telefone' => $dono['telefone'], 'nome' => $dono['nome'], 'dono' => true]];

        $stmt = $this->_conexao->prepare(
            'SELECT ga.telefone, u.nome FROM grupos_admins ga
             LEFT JOIN usuarios u ON u.telefone = ga.telefone
             WHERE ga.id_grupo = ? ORDER BY ga.id'
        );
        $stmt->execute([(int) $g['id']]);
        foreach ($stmt->fetchAll() as $a) {
            $lista[] = ['telefone' => $a['telefone'], 'nome' => $a['nome'], 'dono' => false];
        }

        return $lista;
    }

    /**
     * Troca a lista de administradores convidados (só o dono). $texto = WhatsApps
     * separados por vírgula; o do próprio dono é ignorado.
     */
    private function salvarAdmins($idGrupo, $texto)
    {
        $stmt = $this->_conexao->prepare('SELECT telefone FROM usuarios WHERE id = ?');
        $stmt->execute([$this->idUsuario()]);
        $meu = $stmt->fetchColumn();

        $telefones = [];
        foreach (explode(',', (string) $texto) as $item) {
            if (trim($item) === '') {
                continue;
            }
            $tel = Jogadores::normalizaWhatsapp($item);
            $this->exige($tel !== null, 'WhatsApp de administrador inválido: ' . trim($item) . '. Use DDD + número.');
            if ($tel !== $meu) {
                $telefones[$tel] = true;
            }
        }
        $this->exige(count($telefones) <= 10, 'No máximo 10 administradores além de você.');

        $stmt = $this->_conexao->prepare('DELETE FROM grupos_admins WHERE id_grupo = ?');
        $stmt->execute([(int) $idGrupo]);

        $insere = $this->_conexao->prepare('INSERT INTO grupos_admins (id_grupo, telefone) VALUES (?, ?)');
        foreach (array_keys($telefones) as $tel) {
            $insere->execute([(int) $idGrupo, (string) $tel]);
        }
    }

    /** Os dois coletes do grupo: [['nome' => .., 'cor' => ..], ['nome' => .., 'cor' => ..]] */
    public static function coletes(array $g)
    {
        return [
            ['nome' => $g['colete1_nome'], 'cor' => $g['colete1_cor']],
            ['nome' => $g['colete2_nome'], 'cor' => $g['colete2_cor']],
        ];
    }

    /** @return int id do grupo */
    public function salvar(array $dados)
    {
        $id = isset($dados['id']) ? (int) $dados['id'] : 0;
        $nome = trim(isset($dados['nome']) ? $dados['nome'] : '');
        $porTime = isset($dados['jogadores_por_time']) ? (int) $dados['jogadores_por_time'] : 0;
        $recorrente = (isset($dados['recorrente']) && $dados['recorrente'] === 's') ? 's' : 'n';

        $this->exige($nome !== '' && mb_strlen($nome) <= 100, 'Informe o nome do grupo.');
        $this->exige($porTime >= 2 && $porTime <= 11, 'Jogadores por time: de 2 a 11.');

        $diaSemana = null;
        $horario = null;
        if ($recorrente === 's') {
            $diaSemana = isset($dados['dia_semana']) && $dados['dia_semana'] !== '' ? (int) $dados['dia_semana'] : -1;
            $horario = isset($dados['horario']) ? trim($dados['horario']) : '';
            $this->exige($diaSemana >= 0 && $diaSemana <= 6, 'Escolha o dia da semana do jogo fixo.');
            $this->exige(
                preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horario),
                'Informe o horário do jogo fixo (ex.: 20:00).'
            );
            $horario .= ':00';
        }

        $idArena = isset($dados['id_arena_padrao']) ? (int) $dados['id_arena_padrao'] : 0;
        if ($idArena > 0) {
            $stmt = $this->_conexao->prepare("SELECT id FROM arenas WHERE id = ? AND excluido = 'n'");
            $stmt->execute([$idArena]);
            $this->exige($stmt->fetch(), 'Arena não encontrada.');
        }

        $atual = $id > 0 ? $this->grupoDoUsuario($id) : null;
        $pix = $this->validarPix($dados);
        $coletes = $this->validarColetes($dados, $atual);
        // A lista de administradores só é mexida pelo dono e só quando vem no formulário.
        $mexeAdmins = array_key_exists('admins', $dados) && (!$atual || $atual['dono']);

        if ($id > 0) {
            $stmt = $this->_conexao->prepare(
                'UPDATE grupos SET nome = ?, jogadores_por_time = ?, recorrente = ?, dia_semana = ?, horario = ?,
                        id_arena_padrao = ?, pix_tipo = ?, pix_chave = ?, pix_nome = ?, pix_cidade = ?,
                        colete1_nome = ?, colete1_cor = ?, colete2_nome = ?, colete2_cor = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $nome, $porTime, $recorrente, $diaSemana, $horario, $idArena ?: null,
                $pix['tipo'], $pix['chave'], $pix['nome'], $pix['cidade'],
                $coletes[0]['nome'], $coletes[0]['cor'], $coletes[1]['nome'], $coletes[1]['cor'],
                $id,
            ]);
            if ($mexeAdmins) {
                $this->salvarAdmins($id, $dados['admins']);
            }

            return $id;
        }

        $stmt = $this->_conexao->prepare(
            'INSERT INTO grupos (id_usuario, nome, jogadores_por_time, recorrente, dia_semana, horario,
                    id_arena_padrao, pix_tipo, pix_chave, pix_nome, pix_cidade,
                    colete1_nome, colete1_cor, colete2_nome, colete2_cor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->idUsuario(), $nome, $porTime, $recorrente, $diaSemana, $horario, $idArena ?: null,
            $pix['tipo'], $pix['chave'], $pix['nome'], $pix['cidade'],
            $coletes[0]['nome'], $coletes[0]['cor'], $coletes[1]['nome'], $coletes[1]['cor'],
        ]);
        $id = (int) $this->_conexao->lastInsertId();
        if ($mexeAdmins) {
            $this->salvarAdmins($id, $dados['admins']);
        }

        return $id;
    }

    public function excluir($id)
    {
        $grupo = $this->grupoDoUsuario($id);
        $this->exige($grupo['dono'], 'Só quem criou o grupo pode excluí-lo.');

        $stmt = $this->_conexao->prepare("UPDATE grupos SET excluido = 's' WHERE id = ? AND id_usuario = ?");
        $stmt->execute([(int) $id, $this->idUsuario()]);
    }

    /** Nome e cor dos dois coletes. Sem informar, vale o que já estava (ou o padrão); as duas cores precisam ser diferentes. */
    private function validarColetes(array $dados, $atual = null)
    {
        $coletes = [];
        foreach ([1, 2] as $n) {
            $padrao = $atual
                ? [$atual["colete{$n}_nome"], $atual["colete{$n}_cor"]]
                : self::COLETES_PADRAO[$n - 1];
            $nome = trim(isset($dados["colete{$n}_nome"]) ? $dados["colete{$n}_nome"] : '');
            $cor = trim(isset($dados["colete{$n}_cor"]) ? $dados["colete{$n}_cor"] : '');

            if ($cor === '') {
                $cor = $padrao[1];
                $nome = $nome !== '' ? $nome : $padrao[0];
            }
            $this->exige(preg_match('/^#[0-9A-Fa-f]{6}$/', $cor), "Cor do colete {$n} inválida.");
            $this->exige($nome !== '' && mb_strlen($nome) <= 20, "Informe o nome do colete {$n} (até 20 letras).");

            $coletes[] = ['nome' => $nome, 'cor' => strtoupper($cor)];
        }

        $this->exige(
            $coletes[0]['cor'] !== $coletes[1]['cor'],
            'Os dois coletes precisam ter cores diferentes.'
        );

        return $coletes;
    }

    /**
     * Pix é opcional no cadastro do grupo, mas se começar a preencher precisa estar completo.
     * A chave é guardada no formato que o Pix exige (celular com +55, CPF/CNPJ só números).
     */
    private function validarPix(array $dados)
    {
        $tipo = isset($dados['pix_tipo']) ? trim($dados['pix_tipo']) : '';
        $chave = trim(isset($dados['pix_chave']) ? $dados['pix_chave'] : '');
        $nome = trim(isset($dados['pix_nome']) ? $dados['pix_nome'] : '');
        $cidade = trim(isset($dados['pix_cidade']) ? $dados['pix_cidade'] : '');

        if ($tipo === '' && $chave === '' && $nome === '' && $cidade === '') {
            return ['tipo' => null, 'chave' => null, 'nome' => null, 'cidade' => null];
        }

        $this->exige(
            in_array($tipo, ['cpf', 'cnpj', 'celular', 'email', 'aleatoria'], true),
            'Escolha o tipo da chave Pix.'
        );
        $this->exige($chave !== '', 'Informe a chave Pix.');
        $this->exige($nome !== '', 'Informe o nome de quem recebe o Pix.');
        $this->exige($cidade !== '', 'Informe a cidade de quem recebe o Pix.');

        switch ($tipo) {
            case 'cpf':
                $chave = soNumero($chave);
                $this->exige(strlen($chave) === 11, 'CPF deve ter 11 números.');
                break;
            case 'cnpj':
                $chave = soNumero($chave);
                $this->exige(strlen($chave) === 14, 'CNPJ deve ter 14 números.');
                break;
            case 'celular':
                $n = soNumero($chave);
                if (strlen($n) > 11 && strncmp($n, '55', 2) === 0) {
                    $n = substr($n, 2);
                }
                $this->exige(strlen($n) === 11 || strlen($n) === 10, 'Celular com DDD (ex.: 62 99999-8888).');
                $chave = '+55' . $n;
                break;
            case 'email':
                $chave = mb_strtolower($chave);
                $this->exige(filter_var($chave, FILTER_VALIDATE_EMAIL) && strlen($chave) <= 77, 'E-mail da chave Pix inválido.');
                break;
            case 'aleatoria':
                $chave = strtolower($chave);
                $this->exige(
                    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $chave),
                    'Chave aleatória inválida (formato xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).'
                );
                break;
        }

        return [
            'tipo' => $tipo,
            'chave' => $chave,
            'nome' => mb_substr($nome, 0, 25),
            'cidade' => mb_substr($cidade, 0, 15),
        ];
    }

    private function resumoFixo(array $g)
    {
        if ($g['recorrente'] !== 's' || $g['dia_semana'] === null || !$g['horario']) {
            return null;
        }

        return 'Toda ' . diaSemanaNome($g['dia_semana']) . ', ' . substr($g['horario'], 0, 5);
    }
}
