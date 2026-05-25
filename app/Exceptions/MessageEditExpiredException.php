<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class MessageEditExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(sprintf(
            'Messages can only be edited for %d minutes after creation.',
            (int) config('chat.messages.edit_window_minutes'),
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'message_edit_expired',
        ], 403);
    }
}
