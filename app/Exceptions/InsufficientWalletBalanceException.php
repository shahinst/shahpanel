<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'موجودی کیف پول کافی نیست. از منوی «درخواست‌های شارژ» موجودی را افزایش دهید.');
    }
}
