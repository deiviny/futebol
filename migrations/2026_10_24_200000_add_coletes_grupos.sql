-- Cores dos dois coletes do grupo (normalmente o pessoal tem coletes de duas cores)
ALTER TABLE grupos
    ADD COLUMN colete1_nome VARCHAR(20) NOT NULL DEFAULT 'Laranja',
    ADD COLUMN colete1_cor CHAR(7) NOT NULL DEFAULT '#FF9500',
    ADD COLUMN colete2_nome VARCHAR(20) NOT NULL DEFAULT 'Azul',
    ADD COLUMN colete2_cor CHAR(7) NOT NULL DEFAULT '#007AFF';

-- Colete que cada time veste agora (1 ou 2). NULL = time na fila, sem colete.
ALTER TABLE jogos_times
    ADD COLUMN colete TINYINT UNSIGNED NULL;
