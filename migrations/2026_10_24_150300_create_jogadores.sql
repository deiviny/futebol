-- Jogador é único por WhatsApp e pode participar de vários grupos.
CREATE TABLE IF NOT EXISTS jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL COMMENT 'So digitos, com DDI (ex.: 5562999998888)',
    instagram VARCHAR(60) NULL,
    data_nascimento DATE NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jogadores_whatsapp (whatsapp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo jogador <-> grupo. Score, "quem convidou" e situação são por grupo.
CREATE TABLE IF NOT EXISTS grupos_jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_grupo INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    score DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT '0.0 a 10.0, definido pelo admin',
    id_convidado_por INT UNSIGNED NULL COMMENT 'Jogador que convidou',
    ativo ENUM('s','n') NOT NULL DEFAULT 's',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grupo_jogador (id_grupo, id_jogador),
    KEY idx_gj_jogador (id_jogador),
    CONSTRAINT fk_gj_grupo FOREIGN KEY (id_grupo) REFERENCES grupos (id) ON DELETE CASCADE,
    CONSTRAINT fk_gj_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE,
    CONSTRAINT fk_gj_convidou FOREIGN KEY (id_convidado_por) REFERENCES jogadores (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
