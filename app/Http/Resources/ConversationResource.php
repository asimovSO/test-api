<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myId = $request->user()->id;
        $interlocutor = $this->whenLoaded(
            'users',
            fn() =>
            $this->users->firstWhere('id', '!=', $myId)
        );
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_group' => $this->is_group,
            'users'        => $this->when(
                $this->is_group,
                fn()
                =>
                UserResource::collection($this->whenLoaded('users'))
            ),
            'interlocutor' => $this->when(! $this->is_group, fn() =>
            UserResource::make($interlocutor)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
