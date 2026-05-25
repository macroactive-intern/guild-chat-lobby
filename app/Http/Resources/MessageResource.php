<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Requires `user` to be eager loaded before serialization.
 *
 * Optional nested data:
 * - Load `replies.user` when the response should include threaded replies.
 * - Load `replies.replies.user` for deeper reply nesting.
 *
 * The resource uses whenLoaded() for optional relations so it never lazy-loads
 * them, and the required relation guard fails fast outside production.
 */
class MessageResource extends JsonResource
{
    private const REQUIRED_RELATIONS = [
        'user',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->ensureRequiredRelationsAreLoaded();

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

    private function ensureRequiredRelationsAreLoaded(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (self::REQUIRED_RELATIONS as $relation) {
            if (! $this->resource->relationLoaded($relation)) {
                throw new LogicException(sprintf(
                    '%s requires the [%s] relationship to be eager loaded.',
                    self::class,
                    $relation,
                ));
            }
        }
    }
}
