-- Link do grupo para o jogador se cadastrar sozinho. Quem entra pelo link fica pendente
-- (ativo = 'n') até um administrador aprovar.

ALTER TABLE grupos
    ADD COLUMN convite_token CHAR(32) NULL COMMENT 'Token do link público de inscrição' AFTER pix_cidade,
    ADD COLUMN inscricao_aberta ENUM('s','n') NOT NULL DEFAULT 's' COMMENT 'O link aceita novas inscrições?' AFTER convite_token,
    ADD UNIQUE KEY uq_grupos_convite (convite_token);

-- Grupos que já existiam ganham um link.
UPDATE grupos SET convite_token = MD5(CONCAT(id, '-', RAND(), '-', NOW(6))) WHERE convite_token IS NULL;

ALTER TABLE grupos_jogadores
    ADD COLUMN pendente ENUM('s','n') NOT NULL DEFAULT 'n' COMMENT 'Inscrito pelo link, aguardando aprovação' AFTER ativo;
