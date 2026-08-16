<?php

use App\Events\UnreadCountUpdated;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

function createTestGroup(User $owner, User ...$members): Conversation
{
    return app(ConversationService::class)->firstOrCreateGroup(
        $owner->id,
        array_map(fn (User $u) => $u->id, $members),
        'Test Group'
    );
}

it('counts unread messages from multiple senders in a group conversation', function () {
    /** @var \Tests\TestCase $this */
    $owner = User::factory()->create();
    $bob = User::factory()->create();
    $oleg = User::factory()->create();

    $group = createTestGroup($owner, $bob, $oleg);

    Sanctum::actingAs($bob);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Hi from Bob'])
        ->assertStatus(201);

    Sanctum::actingAs($oleg);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Hi from Oleg'])
        ->assertStatus(201);

    Sanctum::actingAs($owner);
    $response = $this->getJson('/api/conversations');

    $response->assertStatus(200);
    expect($response->json('data.0.unread_messages_count'))->toBe(2);
});

it('resets unread count to zero after mark-as-read and counts only new messages after', function () {
    /** @var \Tests\TestCase $this */
    $owner = User::factory()->create();
    $bob = User::factory()->create();
    $oleg = User::factory()->create();

    $group = createTestGroup($owner, $bob, $oleg);

    Sanctum::actingAs($bob);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Hi from Bob']);
    Sanctum::actingAs($oleg);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Hi from Oleg']);

    Sanctum::actingAs($owner);
    $this->putJson("/api/conversations/{$group->id}/mark-as-read")->assertStatus(200);

    $afterRead = $this->getJson('/api/conversations');
    expect($afterRead->json('data.0.unread_messages_count'))->toBe(0);

    // second-granularity timestamps: push past the mark-as-read second so the
    // next message is unambiguously "after" last_read_at (see CLAUDE.md caveat)
    $this->travel(1)->second();

    Sanctum::actingAs($bob);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Second message from Bob']);

    Sanctum::actingAs($owner);
    $afterNewMessage = $this->getJson('/api/conversations');
    expect($afterNewMessage->json('data.0.unread_messages_count'))->toBe(1);
});

it('broadcasts a correct per-recipient unread count to every other group member', function () {
    /** @var \Tests\TestCase $this */
    Event::fake([UnreadCountUpdated::class]);

    $owner = User::factory()->create();
    $bob = User::factory()->create();
    $oleg = User::factory()->create();

    $group = createTestGroup($owner, $bob, $oleg);

    // owner already read up to now; oleg has never opened the conversation
    $group->users()->updateExistingPivot($owner->id, ['last_read_at' => now()]);
    $this->travel(1)->second();

    Sanctum::actingAs($bob);
    $this->postJson("/api/conversations/{$group->id}/messages", ['body' => 'Hi from Bob'])
        ->assertStatus(201);

    Event::assertDispatched(UnreadCountUpdated::class, function (UnreadCountUpdated $event) use ($owner) {
        return $event->user->id === $owner->id && $event->unreadCount === 1;
    });

    Event::assertDispatched(UnreadCountUpdated::class, function (UnreadCountUpdated $event) use ($oleg) {
        return $event->user->id === $oleg->id && $event->unreadCount === 1;
    });

    // sender never receives their own unread-count update
    Event::assertNotDispatched(UnreadCountUpdated::class, function (UnreadCountUpdated $event) use ($bob) {
        return $event->user->id === $bob->id;
    });
});
