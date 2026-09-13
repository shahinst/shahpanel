<?php

namespace App\Services\Kyc;

use RuntimeException;

class ApiIrException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly mixed $payload = null,
    ) {
        parent::__construct($message);
    }
}
