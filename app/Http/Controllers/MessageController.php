<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use App\Http\Resources\MessageResource;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    //
    public function getMessages(Request $request, Conversation $conversation)
    {
        $authUser = $request->user();
        Gate::authorize('view', $conversation);
        $messages = $conversation->messages()->with('author:id,name')->latest()->paginate(20);
        return MessageResource::collection($messages);
    }

    public function sendMessage(Request $request, Conversation $conversation){
        $authUser = $request->user();

        Gate::authorize('sendMessages', $conversation);

        $validated = request()->validate([
            'body' => 'required|string|min:1|max:2000'
        ]);

        $message = $authUser->messages()->create([
            'conversation_id' => $conversation->id,
            'body' => $validated['body']
        ]);

        return response()->json([
            'message' => new MessageResource($message)
        ], 201);
    }
}
