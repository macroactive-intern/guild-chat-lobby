<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class TooManyMessagesException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are sending messages too quickly.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'too_many_messages',
        ], 429)->withHeaders([
            'Retry-After' => (string) config('chat.messages.rate_limit_seconds'),
        ]);
    }
}
