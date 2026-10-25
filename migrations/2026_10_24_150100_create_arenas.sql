-- Arenas são públicas para uso de qualquer admin; só o criador (id_usuario) edita.
CREATE TABLE IF NOT EXISTS arenas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario INT UNSIGNED NULL COMMENT 'Admin que cadastrou',
    nome VARCHAR(120) NOT NULL,
    valor DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor de referência do aluguel',
    endereco VARCHAR(255) NOT NULL,
    url_mapa VARCHAR(500) NULL COMMENT 'Link do Google Maps',
    excluido ENUM('s','n') NOT NULL DEFAULT 'n',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_arenas_usuario (id_usuario),
    CONSTRAINT fk_arenas_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
