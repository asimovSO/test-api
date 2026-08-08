<?php

use Laravel\Sanctum\Sanctum;
it('tests that a user can send a message in a conversation', function () {
    $sender = \App\Models\User::factory()->create();
    $receiver = \App\Models\User::factory()->create();

    $conversation = \App\Models\Conversation::create();
    $conversation->users()->attach([$sender->id, $receiver->id]);
    Sanctum::actingAs($sender);

    $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
        'body' => 'Hello, this is a test message.',
    ]);
    $response->assertStatus(201);
    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'user_id' => $sender->id,
        'body' => 'Hello, this is a test message.',
    ]);
});
