<?php

namespace Model;

/**
 * Gera o código Pix "copia e cola" (BR Code estático, padrão EMV do Banco Central).
 * É reutilizável: todo mundo do grupo paga com o mesmo código.
 */
final class PixPayload
{
    /**
     * @param string     $chave        Chave Pix como cadastrada (telefone com +55, CPF/CNPJ só dígitos, e-mail ou aleatória).
     * @param string     $nomeRecebedor Até 25 caracteres.
     * @param string     $cidade        Até 15 caracteres.
     * @param float|null $valor         Sem valor, quem paga digita no app do banco.
     * @param string     $txid          '***' = sem identificador.
     */
    public static function gerar($chave, $nomeRecebedor, $cidade, $valor = null, $txid = '***')
    {
        $chave = trim($chave);
        if ($chave === '') {
            throw new \InvalidArgumentException('A chave Pix é obrigatória.');
        }

        $conta = self::campo('00', 'br.gov.bcb.pix') . self::campo('01', $chave);

        $payload = self::campo('00', '01')
            . self::campo('26', $conta)
            . self::campo('52', '0000')
            . self::campo('53', '986')
            . ($valor !== null && $valor > 0 ? self::campo('54', number_format($valor, 2, '.', '')) : '')
            . self::campo('58', 'BR')
            . self::campo('59', self::sanitizar($nomeRecebedor, 25))
            . self::campo('60', self::sanitizar($cidade, 15))
            . self::campo('62', self::campo('05', self::sanitizarTxid($txid)))
            . '6304';

        return $payload . self::crc16($payload);
    }

    /** CRC16/CCITT-FALSE (poly 0x1021, init 0xFFFF) em hexadecimal maiúsculo, 4 dígitos. */
    public static function crc16($dados)
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($dados); $i < $len; $i++) {
            $crc ^= ord($dados[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private static function campo($id, $valor)
    {
        $tamanho = strlen($valor);
        if ($tamanho > 99) {
            throw new \InvalidArgumentException("Valor longo demais para o campo {$id}.");
        }

        return $id . str_pad((string) $tamanho, 2, '0', STR_PAD_LEFT) . $valor;
    }

    /** Remove acentos e símbolos: nome e cidade do Pix devem ser ASCII maiúsculo. */
    private static function sanitizar($texto, $max)
    {
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);

        $texto = strtoupper($texto);
        $texto = preg_replace('/[^A-Z0-9 ]/', '', $texto);
        $texto = trim(preg_replace('/\s+/', ' ', $texto));

        return rtrim(substr($texto, 0, $max));
    }

    private static function sanitizarTxid($txid)
    {
        if ($txid === '***') {
            return $txid;
        }

        $limpo = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);

        return $limpo === '' ? '***' : $limpo;
    }
}
