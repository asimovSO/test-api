<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use App\Models\User;

class ConversationController extends Controller
{
    public function createConversation(Request $request, string $id){
        $sender = $request->user();
        $receiver = User::findOrFail($id);

        Conversation::firstOrCreate([
            'owner_id' => $sender->id,
        ]);
    }
}
