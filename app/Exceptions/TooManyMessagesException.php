<?php

namespace App\Exceptions;

use RuntimeException;

class TooManyMessagesException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are sending messages too quickly.');
    }
}
