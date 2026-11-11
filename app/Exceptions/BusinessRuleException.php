<?php

namespace App\Exceptions;

use Exception;

class BusinessRuleException extends Exception
{
    /**
     * Menyimpan kode error string (misal: 'VALIDASI_GAGAL', 'QTY_MELEBIHI_BATAS')
     */
    protected $codeBisnis;

    /**
     * Constructor BusinessRuleException.
     * 
     * @param string $message     Pesan error untuk user.
     * @param string $codeBisnis  Kode string aturan bisnis.
     * @param int    $httpCode    HTTP Status Code (default 422 Unprocessable Entity).
     */
    public function __construct(string $message, string $codeBisnis = 'BUSINESS_ERROR', int $httpCode = 422)
    {
        // Panggil constructor Exception bawaan PHP (message, code (int))
        parent::__construct($message, $httpCode);
        
        $this->codeBisnis = $codeBisnis;
    }

    /**
     * Getter untuk mengambil kode bisnis (dipanggil di try-catch pada Test).
     */
    public function getCodeBisnis(): string
    {
        return $this->codeBisnis;
    }
}