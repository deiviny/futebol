-- Partidas de um jogo (a "pelada" da noite tem várias partidas)
CREATE TABLE IF NOT EXISTS jogos_partidas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogo INT UNSIGNED NOT NULL,
    numero SMALLINT UNSIGNED NOT NULL,
    situacao ENUM('em_andamento','finalizada','descartada') NOT NULL DEFAULT 'em_andamento',

    -- lado a = colete 1, lado b = colete 2 (o time que fica mantém o colete e o lado)
    id_time_a INT UNSIGNED NOT NULL,
    id_time_b INT UNSIGNED NOT NULL,
    nome_a VARCHAR(40) NOT NULL,
    nome_b VARCHAR(40) NOT NULL,
    colete_a TINYINT UNSIGNED NOT NULL DEFAULT 1,
    colete_b TINYINT UNSIGNED NOT NULL DEFAULT 2,
    colete_nome_a VARCHAR(20) NOT NULL,
    colete_nome_b VARCHAR(20) NOT NULL,
    cor_a CHAR(7) NOT NULL,
    cor_b CHAR(7) NOT NULL,

    iniciada_em DATETIME NOT NULL,
    finalizada_em DATETIME NULL,
    gols_a SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    gols_b SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lado_vencedor ENUM('a','b') NULL COMMENT 'NULL = empate (ou partida ainda aberta)',
    id_time_entrou INT UNSIGNED NULL COMMENT 'Time que entrou na quadra no inicio desta partida (NULL na primeira)',
    id_time_saiu INT UNSIGNED NULL COMMENT 'Time que deixou a quadra ao fim da partida',

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_partidas_jogo (id_jogo, numero),
    CONSTRAINT fk_partidas_jogo FOREIGN KEY (id_jogo) REFERENCES jogos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem jogou cada partida e de que lado (foto do momento, não muda se o time mudar depois)
CREATE TABLE IF NOT EXISTS jogos_partidas_jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_partida INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    lado ENUM('a','b') NOT NULL,
    emprestado ENUM('s','n') NOT NULL DEFAULT 'n' COMMENT 's = veio do time que perdeu para completar o time',
    id_time_origem INT UNSIGNED NULL,
    nome_time_origem VARCHAR(40) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_partida_jogador (id_partida, id_jogador),
    KEY idx_pj_jogador (id_jogador),
    CONSTRAINT fk_pj_partida FOREIGN KEY (id_partida) REFERENCES jogos_partidas (id) ON DELETE CASCADE,
    CONSTRAINT fk_pj_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gols (autor, lado que marcou, gol contra e o momento da partida)
CREATE TABLE IF NOT EXISTS jogos_gols (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_partida INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NULL COMMENT 'NULL = não sei quem fez',
    lado ENUM('a','b') NOT NULL COMMENT 'Lado que ganhou o gol no placar',
    contra ENUM('s','n') NOT NULL DEFAULT 'n',
    segundos INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tempo de partida em que saiu',
    criado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    excluido ENUM('s','n') NOT NULL DEFAULT 'n',
    PRIMARY KEY (id),
    KEY idx_gols_partida (id_partida),
    KEY idx_gols_jogador (id_jogador),
    CONSTRAINT fk_gols_partida FOREIGN KEY (id_partida) REFERENCES jogos_partidas (id) ON DELETE CASCADE,
    CONSTRAINT fk_gols_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
