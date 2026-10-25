-- A fila de espera deixa de ser "times prontos" e vira uma LISTA de jogadores, na ordem.
-- Quem perde vai para o fim da lista; o próximo time sai dos primeiros da lista.

-- Posição do jogador dentro do time (segue a ordem da lista de onde ele veio).
ALTER TABLE jogos_times_jogadores
    ADD COLUMN ordem INT UNSIGNED NOT NULL DEFAULT 0;

UPDATE jogos_times_jogadores SET ordem = id;

-- Lista de espera do jogo, do primeiro ao último.
CREATE TABLE IF NOT EXISTS jogos_fila (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jogo INT UNSIGNED NOT NULL,
    id_jogador INT UNSIGNED NOT NULL,
    ordem INT UNSIGNED NOT NULL,
    score DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT 'Score do jogador no momento em que entrou no jogo',
    id_partida_saiu INT UNSIGNED NULL COMMENT 'Partida em que ele saiu da quadra (NULL = ainda não jogou)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_fila_jogador (id_jogo, id_jogador),
    KEY idx_fila_ordem (id_jogo, ordem),
    CONSTRAINT fk_fila_jogo FOREIGN KEY (id_jogo) REFERENCES jogos (id) ON DELETE CASCADE,
    CONSTRAINT fk_fila_jogador FOREIGN KEY (id_jogador) REFERENCES jogadores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jogos que já tinham times esperando: os jogadores passam para a lista, na mesma ordem.
-- (Contagem em subconsulta em vez de ROW_NUMBER(): o MySQL 5.7 da hospedagem não tem window functions.)
INSERT INTO jogos_fila (id_jogo, id_jogador, ordem, score)
SELECT t.id_jogo, tj.id_jogador,
       (SELECT COUNT(*)
          FROM jogos_times t2
          JOIN jogos_times_jogadores tj2 ON tj2.id_time = t2.id
         WHERE t2.colete IS NULL
           AND t2.id_jogo = t.id_jogo
           AND (t2.posicao < t.posicao
                OR (t2.posicao = t.posicao AND (t2.id < t.id
                    OR (t2.id = t.id AND tj2.ordem <= tj.ordem))))),
       tj.score
FROM jogos_times t
JOIN jogos_times_jogadores tj ON tj.id_time = t.id
WHERE t.colete IS NULL;

DELETE FROM jogos_times_jogadores
WHERE id_time IN (SELECT id FROM (SELECT id FROM jogos_times WHERE colete IS NULL) AS esperando);

DELETE FROM jogos_times WHERE colete IS NULL;

-- Empate: par ou ímpar (o app sorteia os números) ou escolha manual.
ALTER TABLE jogos_partidas
    ADD COLUMN desempate ENUM('par_impar','manual') NULL COMMENT 'Como se decidiu quem sai quando deu empate',
    ADD COLUMN desempate_lado_par ENUM('a','b') NULL COMMENT 'Lado que ficou com o PAR',
    ADD COLUMN desempate_num_a TINYINT UNSIGNED NULL COMMENT 'Número sorteado para o lado a',
    ADD COLUMN desempate_num_b TINYINT UNSIGNED NULL COMMENT 'Número sorteado para o lado b';
