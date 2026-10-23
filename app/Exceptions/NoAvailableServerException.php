<?php

namespace App\Exceptions;

use RuntimeException;

class NoAvailableServerException extends RuntimeException
{
    public function __construct(string $message = 'No active server is currently available to host new accounts.')
    {
        parent::__construct($message);
    }
}
