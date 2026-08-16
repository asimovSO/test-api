<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    private function isParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }
    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group ? $conversation->owner_id === $user->id : $this->isParticipant($user, $conversation);
    }

    public function sendMessages(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    public function markAsRead(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    public function addParticipants(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group && $conversation->owner_id === $user->id;
    }

    public function removeParticipants(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group && $conversation->owner_id === $user->id;
    }

    public function quitConversation(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group && $this->isParticipant($user, $conversation);
    }
}
