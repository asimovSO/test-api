<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use App\Models\User;

class ConversationController extends Controller
{
    public function createConversation(Request $request, string $id){
        $user = $request->user();

        Conversation::firstOrCreate([
            'owner_id' => $user->id
        ]);
    }
}
