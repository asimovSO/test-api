<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\CreateGroupConversationRequest;

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

    public function createGroupConversation(
        CreateGroupConversationRequest $request,
        ConversationService $service
    ) {
        $validated = $request->validated();

        $authUser = $request->user();
        $conversation = $service->firstOrCreateGroup($authUser->id, $validated['user_ids'], $validated['name']);

        return response()->json([
            'message' => 'Created',
            'conversation' => new ConversationResource($conversation),
        ], 201);
    }

    public function getAllUserConversations(Request $request)
    {
        $myId = $request->user()->id;
        $conversations = $request->user()->conversations()
            ->with('users:id,name', 'lastMessage:messages.id,messages.body,messages.conversation_id')
            ->withCount(['messages as unread_messages_count' => fn($query) => $query->where('messages.user_id', '!=', $myId)->where(fn($query) => $query->whereColumn('messages.created_at', '>', 'conversation_user.last_read_at')->orWhereNull('conversation_user.last_read_at'))])
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

    public function addParticipant(Request $request, Conversation $conversation, User $user)
    {
        Gate::authorize('addParticipants', $conversation);

        if ($conversation->users()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'User is already a participant',
            ], 400);
        }

        $conversation->users()->syncWithoutDetaching([$user->id]);

        return response()->json([
            'message' => 'Participant added',
            'conversation' => new ConversationResource($conversation->load('users')),
        ], 200);
    }

    public function removeParticipant(Request $request, Conversation $conversation, User $user)
    {
        Gate::authorize('removeParticipants', $conversation);

        $conversation->users()->detach($user->id);

        return response()->json([
            'message' => 'Participant removed',
            'conversation' => new ConversationResource($conversation->load('users')),
        ], 200);
    }

    public function quitConversation(Request $request, Conversation $conversation)
    {
        Gate::authorize('quitConversation', $conversation);

        if ($conversation->owner_id === $request->user()->id) {
            $newOwner = $conversation->users()->where('user_id', '!=', $conversation->owner_id)->first();

            if (!$newOwner) {
                $conversation->delete();

                return response()->json([
                    'message' => 'You have left the conversation',
                ], 200);
            }

            $conversation->owner_id = $newOwner->id;
            $conversation->save();
        }

        $conversation->users()->detach($request->user()->id);

        return response()->json([
            'message' => 'You have left the conversation'
        ], 200);
    }
}
