<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\ConversationService;
use App\Models\Conversation;
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
                'conversation' => new ConversationResource($conversation)
            ], 201);
        }

        return response()->json([
            'message' => 'Was existing',
            'conversation' => new ConversationResource($conversation)
        ], 200);
    }

    public function getAllUserConversations(Request $request){
        $conversations = $request->user()->conversations()
            ->with('users:id,name', 'lastMessage:messages.id,messages.body,messages.conversation_id')
            ->orderByDesc('conversations.updated_at')
            ->paginate(20);
        return ConversationResource::collection($conversations);
    }

    public function deleteConversation(Request $request, ConversationService $service, Conversation $conversation)
    {
        Gate::authorize('delete', $conversation);

        $service->deleteConversation($conversation);

        return response()->json([
            'message' => 'Conversation deleted'
        ], 200);
    }
}
