<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->trashed() ? '[message deleted]' : $this->body,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'parent_id' => $this->parent_id,
            'replies' => MessageResource::collection($this->whenLoaded('replies')),
            'edited_at' => $this->edited_at,
            'is_deleted' => $this->trashed(),
            'created_at' => $this->created_at,
        ];
    }
}
