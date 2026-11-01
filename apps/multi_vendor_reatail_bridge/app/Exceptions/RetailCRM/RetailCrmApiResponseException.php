<?php

namespace App\Exceptions\RetailCRM;

class RetailCrmApiResponseException extends RetailCrmException
{
    protected $errorResponse;

    public function __construct($message = "", $code = 0, $previous = null, $errorResponse = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errorResponse = $errorResponse;
    }

    public function getErrorResponse()
    {
        return $this->errorResponse;
    }
}
