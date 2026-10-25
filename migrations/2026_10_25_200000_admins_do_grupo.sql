-- Um grupo pode ter mais de um administrador. Quem cria o grupo (grupos.id_usuario) informa
-- o WhatsApp dos outros; quem tiver conta com esse WhatsApp passa a administrar o grupo.

-- WhatsApp do administrador (só dígitos, com DDI: 5562999998888). Identifica quem é convidado.
ALTER TABLE usuarios
    ADD COLUMN telefone VARCHAR(15) NULL COMMENT 'WhatsApp só dígitos com DDI' AFTER email,
    ADD UNIQUE KEY uq_usuarios_telefone (telefone);

CREATE TABLE IF NOT EXISTS grupos_admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_grupo INT UNSIGNED NOT NULL,
    telefone VARCHAR(15) NOT NULL COMMENT 'WhatsApp do admin convidado (mesmo formato de usuarios.telefone)',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grupos_admins (id_grupo, telefone),
    KEY idx_grupos_admins_telefone (telefone),
    CONSTRAINT fk_grupos_admins_grupo FOREIGN KEY (id_grupo) REFERENCES grupos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
