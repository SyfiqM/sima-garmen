<?php

namespace App\Exceptions;

use Exception;

class BusinessRuleException extends Exception
{
    protected $codeName;

    public function __construct($message, $codeName = 'ATURAN_BISNIS_DILANGGAR', $statusCode = 422)
    {
        parent::__construct($message, $statusCode);
        $this->codeName = $codeName;
    }

    public function render($request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->codeName,
        ], $this->getCode() ?: 422);
    }
}