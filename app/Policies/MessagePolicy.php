<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Message;

class MessagePolicy
{

    private function isAuthor(User $user, Message $message): bool
    {
        return $user->id === $message->user_id;
    }

    public function edit(User $user, Message $message): bool
    {
        return $this->isAuthor($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->isAuthor($user, $message);
    }
}
