<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\ConversationService;

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
                'conversation' => $conversation
            ], 201);
        }

        return response()->json([
            'message' => 'Was existing',
            'conversation' => $conversation
        ], 200);
    }

    public function getAllUserConversations(Request $request){
        $conversations = $request->user()->conversations()->with('users:id,name')->get();
        return response()->json([
            'conversations' => $conversations
        ], 200);
    }
}
