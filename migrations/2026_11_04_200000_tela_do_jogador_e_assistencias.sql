-- Tela do jogador (link do jogo ao vivo) e assistência nos gols.

-- Quem deu o passe para o gol e quem marcou o gol pela tela do jogador.
ALTER TABLE jogos_gols
    ADD COLUMN id_assistencia INT UNSIGNED NULL COMMENT 'Quem deu a assistência (NULL = sem assistência ou não informada)' AFTER id_jogador,
    ADD COLUMN id_jogador_registrou INT UNSIGNED NULL COMMENT 'Jogador que marcou o gol pela tela do jogador (NULL = administrador)' AFTER excluido,
    ADD KEY idx_gols_assistencia (id_assistencia),
    ADD CONSTRAINT fk_gols_assistencia FOREIGN KEY (id_assistencia) REFERENCES jogadores (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_gols_registrou FOREIGN KEY (id_jogador_registrou) REFERENCES jogadores (id) ON DELETE SET NULL;

-- Login do jogador pelo telefone: fica guardado no celular (cookie) e o banco só guarda o hash.
CREATE TABLE IF NOT EXISTS jogadores_sessoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogador INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 do token guardado no cookie',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usado_em DATETIME NULL,
    expira_em DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jogadores_sessoes_token (token_hash),
    KEY idx_jogadores_sessoes_jogador (id_jogador),
    CONSTRAINT fk_jogadores_sessoes_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
