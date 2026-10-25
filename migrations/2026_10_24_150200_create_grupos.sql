-- Grupos de futebol (ex.: "Futebol de sexta"). Cada grupo pertence a um admin (id_usuario).
CREATE TABLE IF NOT EXISTS grupos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario INT UNSIGNED NOT NULL COMMENT 'Admin dono do grupo (tenant)',
    nome VARCHAR(100) NOT NULL,
    jogadores_por_time TINYINT UNSIGNED NOT NULL DEFAULT 5,

    -- Jogo fixo semanal
    recorrente ENUM('s','n') NOT NULL DEFAULT 'n',
    dia_semana TINYINT UNSIGNED NULL COMMENT '0=domingo ... 5=sexta ... 6=sabado',
    horario TIME NULL,
    id_arena_padrao INT UNSIGNED NULL,

    -- Dados para o Pix copia e cola
    pix_tipo ENUM('cpf','cnpj','celular','email','aleatoria') NULL,
    pix_chave VARCHAR(77) NULL COMMENT 'Já no formato do Pix (celular com +55)',
    pix_nome VARCHAR(25) NULL COMMENT 'Nome do recebedor (max. 25)',
    pix_cidade VARCHAR(15) NULL COMMENT 'Cidade do recebedor (max. 15)',

    excluido ENUM('s','n') NOT NULL DEFAULT 'n',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_grupos_usuario (id_usuario, excluido),
    CONSTRAINT fk_grupos_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_grupos_arena FOREIGN KEY (id_arena_padrao) REFERENCES arenas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
