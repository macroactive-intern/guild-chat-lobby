<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ArchivedRoomException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Archived rooms cannot receive new messages.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'archived_room',
        ], 409);
    }
}
