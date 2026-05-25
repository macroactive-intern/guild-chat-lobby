<?php

namespace App\Http\Requests;

use App\Models\Message;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $room = $this->messageRoom();

                if (! $room) {
                    return;
                }

                if ($room->is_archived) {
                    $validator->errors()->add('room_id', 'Archived rooms cannot receive new messages.');
                }

                if (! $this->filled('parent_id')) {
                    return;
                }

                $parentBelongsToRoom = Message::query()
                    ->whereKey($this->integer('parent_id'))
                    ->where('room_id', $room->id)
                    ->exists();

                if (! $parentBelongsToRoom) {
                    $validator->errors()->add('parent_id', 'The parent message must belong to the same room.');
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }

    private function messageRoom(): ?Room
    {
        $room = $this->route('room');

        if ($room instanceof Room) {
            return $room;
        }

        if ($room) {
            return Room::find($room);
        }

        if ($this->filled('room_id')) {
            return Room::find($this->integer('room_id'));
        }

        return null;
    }
}
