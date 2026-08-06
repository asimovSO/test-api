<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use App\Http\Resources\MessageResource;

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

        return $conversation->messages();
    }

    public function sendMessage(Request $request, Conversation $conversation){
        $authUser = $request->user();

        if($conversation->users()->where('user_id', $authUser->id)->doesntExist()){
            return response()->json([
                'message' => 'You are not a participant of this conversation'
            ], 403);
        }

        $message = request()->validate([
            'body' => 'required|string|min:1|max:2000'
        ]);

        $authUser->messages()->create([
            'conversation_id' => $conversation->id,
            'body' => $message['body']
        ]);

        return response()->json([
            'message' => new MessageResource($message)
        ], 201);
    }
}
