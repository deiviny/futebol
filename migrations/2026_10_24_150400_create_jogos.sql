-- Jogos do grupo
CREATE TABLE IF NOT EXISTS jogos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_grupo INT UNSIGNED NOT NULL,
    id_arena INT UNSIGNED NULL,
    data_hora DATETIME NOT NULL,
    situacao ENUM('agendado','em_andamento','finalizado','cancelado') NOT NULL DEFAULT 'agendado',
    motivo_cancelamento VARCHAR(200) NULL COMMENT 'Quando situacao = cancelado ("sem jogo neste dia")',
    jogadores_por_time TINYINT UNSIGNED NOT NULL DEFAULT 5,
    token CHAR(32) NOT NULL COMMENT 'Link publico de confirmacao de presenca',

    -- Fechamento
    valor_total DECIMAL(10,2) NULL,
    valor_por_jogador DECIMAL(10,2) NULL,
    codigo_pix TEXT NULL,
    finalizado_em DATETIME NULL,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jogos_token (token),
    UNIQUE KEY uq_jogos_grupo_data (id_grupo, data_hora),
    KEY idx_jogos_data (data_hora),
    CONSTRAINT fk_jogos_grupo FOREIGN KEY (id_grupo) REFERENCES grupos (id) ON DELETE CASCADE,
    CONSTRAINT fk_jogos_arena FOREIGN KEY (id_arena) REFERENCES arenas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Confirmação de presença (resposta) e chegada no dia do jogo
CREATE TABLE IF NOT EXISTS jogos_presencas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogo INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    resposta ENUM('confirmado','recusado') NOT NULL DEFAULT 'confirmado',
    confirmado_em DATETIME NULL,
    chegada_em DATETIME(3) NULL COMMENT 'A ordem de chegada define quem entra nos primeiros times',
    PRIMARY KEY (id),
    UNIQUE KEY uq_presenca (id_jogo, id_jogador),
    KEY idx_presenca_jogador (id_jogador),
    CONSTRAINT fk_presenca_jogo FOREIGN KEY (id_jogo) REFERENCES jogos (id) ON DELETE CASCADE,
    CONSTRAINT fk_presenca_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Times sorteados do jogo
CREATE TABLE IF NOT EXISTS jogos_times (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogo INT UNSIGNED NOT NULL,
    nome VARCHAR(40) NOT NULL,
    cor CHAR(7) NOT NULL DEFAULT '#8E8E93',
    posicao TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_times_jogo (id_jogo),
    CONSTRAINT fk_times_jogo FOREIGN KEY (id_jogo) REFERENCES jogos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jogos_times_jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_time INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    score DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT 'Score do jogador no momento do sorteio',
    PRIMARY KEY (id),
    UNIQUE KEY uq_time_jogador (id_time, id_jogador),
    KEY idx_tj_jogador (id_jogador),
    CONSTRAINT fk_tj_time FOREIGN KEY (id_time) REFERENCES jogos_times (id) ON DELETE CASCADE,
    CONSTRAINT fk_tj_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cobrança de cada jogador presente
CREATE TABLE IF NOT EXISTS jogos_pagamentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogo INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    pago_em DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pagamento (id_jogo, id_jogador),
    KEY idx_pag_jogador (id_jogador),
    CONSTRAINT fk_pag_jogo FOREIGN KEY (id_jogo) REFERENCES jogos (id) ON DELETE CASCADE,
    CONSTRAINT fk_pag_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
