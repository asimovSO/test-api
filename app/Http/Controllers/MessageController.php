<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    //
    public function getMessages(Request $request, Conversation $conversation)
    {
        $authUser = $request->user();
        if (! $conversation->users()->where('user_id', $authUser->id)->exists()) {
            return response()->json([
                'message' => 'You are not a participant of this conversation'
            ], 403);
        }

        return Message::where('conversation_id', $conversation->id)->get();
    }

    public function sendMessage(Request $request, Conversation $conversation){
        $authUser = $request->user();
        $message = request()->validate([
            'body' => 'required|string'
        ]);

        $authUser->messages()->create([
            'conversation_id' => $conversation->id,
            'body' => $message['body']
        ]);

        return response()->json([
            'message' => 'Message sent'
        ], 201);
    }
}
