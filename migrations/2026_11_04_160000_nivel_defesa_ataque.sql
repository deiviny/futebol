-- Nível de defesa e nível de ataque de cada jogador (por grupo), de 0 a 10.
-- Os dois começam com o score que o jogador já tinha, então nada se perde.
-- O score continua existindo e passa a ser a média dos dois (defesa + ataque) / 2.
ALTER TABLE grupos_jogadores
    ADD COLUMN defesa DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT 'Nivel de defesa, 0.0 a 10.0' AFTER score,
    ADD COLUMN ataque DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT 'Nivel de ataque, 0.0 a 10.0' AFTER defesa;

UPDATE grupos_jogadores SET defesa = score, ataque = score;
