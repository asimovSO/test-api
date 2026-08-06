<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function firstOrCreate(int $userA, int $userB)
    {
        if ($userA == $userB) {
            throw ValidationException::withMessages([
                'user' => 'Cant chat with yourself',
            ]);
        }

        $existing_chat = Conversation::where('is_group', false)
            ->whereRelation('users', 'user_id', $userA)
            ->whereRelation('users', 'user_id', $userB)
            ->first();

        if ($existing_chat) {
            return $existing_chat->load('users');
        }

        return DB::transaction(function () use ($userA, $userB) {
            $conversation = Conversation::create(
                ["is_group" => false]
            );

            $conversation->users()->attach([$userA, $userB]);
            return $conversation->load('users');
        });
    }

    public function getConversationById(int $conversationId)
    {
        return Conversation::find($conversationId);
    }

    public function deleteConversation(Conversation $conversation)
    {
        $conversation->delete();
    }
}
