<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{conversation}', function ($user, Conversation $conversation) {
    return $user->can('view', $conversation);
});

Broadcast::channel('online', function ($user) {
    return new \App\Http\Resources\UserResource($user);
});
