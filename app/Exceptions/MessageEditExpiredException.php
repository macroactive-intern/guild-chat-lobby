<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class MessageEditExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Messages can only be edited for 10 minutes after creation.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'message_edit_expired',
        ], 403);
    }
}
