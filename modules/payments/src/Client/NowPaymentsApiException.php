<?php

namespace Modules\NowPayments\Client;

class NowPaymentsApiException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
