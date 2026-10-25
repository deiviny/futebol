<?php

namespace Model;

/**
 * Executa as migrations SQL puras de migrations/ (YYYY_MM_DD_HHMMSS_nome.sql).
 *
 * - Cada arquivo roda uma única vez (controle na tabela "migrations").
 * - Arquivo com "deletar" no nome é movido para migrations/backup/ após rodar
 *   com sucesso, para não ser reexecutado por acidente.
 */
class Migrate extends Conect
{
    private $_pasta;

    public function __construct()
    {
        $this->sess_usuario = [];
        $this->_pasta = dirname(__DIR__) . '/migrations';
    }

    /** @return string[] linhas de log */
    public function executar()
    {
        $pdo = $this->conecta();
        $log = [];

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                arquivo VARCHAR(190) NOT NULL,
                executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (arquivo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $feitas = $pdo->query('SELECT arquivo FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);

        $arquivos = glob($this->_pasta . '/*.sql') ?: [];
        sort($arquivos);

        foreach ($arquivos as $caminho) {
            $nome = basename($caminho);

            if (in_array($nome, $feitas, true)) {
                continue;
            }

            $sql = file_get_contents($caminho);

            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $comando) {
                $semComentarios = trim(preg_replace('/^\s*--.*$/m', '', $comando));
                if ($semComentarios === '') {
                    continue;
                }
                $pdo->exec($comando);
            }

            $stmt = $pdo->prepare('INSERT INTO migrations (arquivo) VALUES (?)');
            $stmt->execute([$nome]);
            $log[] = "OK  {$nome}";

            if (stripos($nome, 'deletar') !== false) {
                $backup = $this->_pasta . '/backup';
                if (!is_dir($backup)) {
                    mkdir($backup, 0775, true);
                }
                rename($caminho, $backup . '/' . $nome);
                $log[] = "    movida para migrations/backup/";
            }
        }

        if (!$log) {
            $log[] = 'Nada a executar: banco já está atualizado.';
        }

        return $log;
    }
}
