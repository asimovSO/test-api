<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use App\Http\Resources\MessageResource;
use Illuminate\Support\Facades\Gate;
use App\Events\MessageSent;
use App\Http\Requests\MessageRequest;

class MessageController extends Controller
{
    //
    public function getMessages(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $messages = $conversation->messages()->with('author:id,name')->latest()->cursorPaginate(20);
        return MessageResource::collection($messages);
    }

    public function sendMessage(MessageRequest $request, Conversation $conversation){
        $authUser = $request->user();

        Gate::authorize('sendMessages', $conversation);

        $validated = $request->validated();

        $message = $authUser->messages()->create([
            'conversation_id' => $conversation->id,
            'body' => $validated['body']
        ]);

        MessageSent::dispatch($message->setRelation('author', $authUser));

        return response()->json([
            'message' => new MessageResource($message)
        ], 201);
    }

    public function updateMessage(MessageRequest $request, Message $message)
    {
        Gate::authorize('edit', $message);



        $message->update([
            'body' => $request->validated()['body']
        ]);

        return response()->json([
            'message' => new MessageResource($message)
        ], 200);
    }

    public function deleteMessage(Message $message)
    {
        Gate::authorize('delete', $message);

        $message->delete();

        return response()->json([
            'message' => 'Message deleted',
        ], 200);
    }
}
