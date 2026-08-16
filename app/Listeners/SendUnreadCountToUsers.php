<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
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
        $senderId = $event->message->user_id;

        $users = $conversation->users()->where('users.id', '!=', $senderId)->get(['users.id']);

        $unreadCounts = DB::table('conversation_user as cu')
            ->leftJoin('messages as m', function ($join) {
                $join->on('m.conversation_id', '=', 'cu.conversation_id')
                    ->on('m.user_id', '!=', 'cu.user_id')
                    ->where(fn ($q) => $q->whereColumn('m.created_at', '>', 'cu.last_read_at')
                        ->orWhereNull('cu.last_read_at'));
            })
            ->where('cu.conversation_id', $conversation->id)
            ->where('cu.user_id', '!=', $senderId)
            ->groupBy('cu.user_id')
            ->selectRaw('cu.user_id, COUNT(m.id) as unread_count')
            ->pluck('unread_count', 'user_id');

        foreach ($users as $user) {
            UnreadCountUpdated::dispatch($conversation, $user, (int) $unreadCounts->get($user->id, 0));
        }
    }
}
