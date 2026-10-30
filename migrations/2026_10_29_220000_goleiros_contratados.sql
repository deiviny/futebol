-- Goleiros contratados para o jogo: quantos, quanto cada um cobra e (opcional) os nomes.
-- O valor dos goleiros entra junto com a quadra na divisão entre os jogadores.
ALTER TABLE jogos
    ADD COLUMN goleiros_qtd TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Goleiros contratados' AFTER jogadores_por_time,
    ADD COLUMN goleiros_valor DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Valor de CADA goleiro' AFTER goleiros_qtd,
    ADD COLUMN goleiros_nomes VARCHAR(200) NULL COMMENT 'Nomes separados por vírgula (opcional)' AFTER goleiros_valor;
