<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function createConversation(
        Request $request,
        ConversationService $service,
        User $user
    ) {
        $authUser = $request->user();
        $conversation = $service->firstOrCreate($authUser->id, $user->id);

        if ($conversation->wasRecentlyCreated) {
            return response()->json([
                'message' => 'Created',
                'conversation' => new ConversationResource($conversation),
            ], 201);
        }

        return response()->json([
            'message' => 'Was existing',
            'conversation' => new ConversationResource($conversation),
        ], 200);
    }

    public function getAllUserConversations(Request $request)
    {
        $myId = $request->user()->id;
        $conversations = $request->user()->conversations()
            ->with('users:id,name', 'lastMessage:messages.id,messages.body,messages.conversation_id')
            ->withCount(['messages as unread_messages_count' => fn ($query) => $query->where('messages.user_id', '!=', $myId)->where(fn ($query) => $query->whereColumn('messages.created_at', '>', 'conversation_user.last_read_at')->orWhereNull('conversation_user.last_read_at'))])
            ->orderByDesc('conversations.updated_at')
            ->paginate(20);

        return ConversationResource::collection($conversations);
    }

    public function deleteConversation(Request $request, ConversationService $service, Conversation $conversation)
    {
        Gate::authorize('delete', $conversation);

        $service->deleteConversation($conversation);

        return response()->json([
            'message' => 'Conversation deleted',
        ], 200);
    }

    public function markAsRead(Request $request, Conversation $conversation)
    {
        Gate::authorize('markAsRead', $conversation);

        $conversation->users()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

        return response()->json([
            'message' => 'Conversation marked as read',
        ], 200);
    }
}
