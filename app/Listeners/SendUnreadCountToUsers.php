<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Events\UnreadCountUpdated;
use App\Events\MessageSent;

class SendUnreadCountToUsers
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        $conversation = $event->message->conversation;
        $users = $conversation->users()->where('users.id', '!=', $event->message->user_id)->get(['users.id']);

        foreach ($users as $user) {
            $lastReadAt = $user->pivot->last_read_at;

            $unreadCount = $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                ->count();

            UnreadCountUpdated::dispatch($conversation, $user, $unreadCount);
        }
    }
}
