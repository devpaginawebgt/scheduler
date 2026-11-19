<?php

use App\Jobs\ProcessChatwootMessageJob;
use App\Models\BotConversation;
use App\Models\BotMessage;
use App\Services\ChatwootApiService;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function chatwootPayload(array $overrides = []): array
{
    return array_merge([
        'event' => 'message_created',
        'message_type' => 'incoming',
        'private' => false,
        'content' => 'Hola',
        'conversation' => ['id' => 1],
        'sender' => ['phone_number' => '+50212345678', 'name' => 'Test User'],
    ], $overrides);
}

function postWebhook(array $payload, string $secret = 'test-secret'): TestResponse
{
    $body = json_encode($payload);
    $timestamp = (string) time();
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return test()->postJson('/api/chatwoot/webhook', $payload, [
        'X-Chatwoot-Signature' => $signature,
        'X-Chatwoot-Timestamp' => $timestamp,
    ]);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    config(['chatwoot.webhook_secret' => 'test-secret', 'chatwoot.verify_signature' => true]);
    Queue::fake();
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('returns 401 when signature header is missing', function (): void {
    $this->postJson('/api/chatwoot/webhook', chatwootPayload())
        ->assertStatus(401);
});

it('returns 401 when signature is invalid', function (): void {
    $this->postJson('/api/chatwoot/webhook', chatwootPayload(), [
        'X-Chatwoot-Signature' => 'sha256=badhash',
        'X-Chatwoot-Timestamp' => (string) time(),
    ])->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Filtering
// ---------------------------------------------------------------------------

it('returns 200 and skips non-incoming events', function (): void {
    postWebhook(chatwootPayload(['event' => 'conversation_created']))
        ->assertOk();

    Queue::assertNothingPushed();
});

it('returns 200 and skips outgoing messages', function (): void {
    postWebhook(chatwootPayload(['message_type' => 'outgoing']))
        ->assertOk();

    Queue::assertNothingPushed();
});

it('returns 200 and skips private messages', function (): void {
    postWebhook(chatwootPayload(['private' => true]))
        ->assertOk();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

it('dispatches job for a valid incoming message', function (): void {
    postWebhook(chatwootPayload())->assertOk();

    Queue::assertPushed(ProcessChatwootMessageJob::class);
});

it('deduplicates messages with the same delivery id', function (): void {
    $payload = chatwootPayload();
    $body = json_encode($payload);
    $timestamp = (string) time();
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret');

    $headers = [
        'X-Chatwoot-Signature' => $signature,
        'X-Chatwoot-Timestamp' => $timestamp,
        'X-Chatwoot-Delivery' => 'unique-id-123',
    ];

    $this->postJson('/api/chatwoot/webhook', $payload, $headers)->assertOk();
    $this->postJson('/api/chatwoot/webhook', $payload, $headers)->assertOk();

    Queue::assertPushed(ProcessChatwootMessageJob::class, 1);
});

// ---------------------------------------------------------------------------
// Job — guards
// ---------------------------------------------------------------------------

it('job skips processing when conversation is in handoff state', function (): void {
    $gemini = Mockery::mock(GeminiService::class);
    $gemini->shouldNotReceive('classify');
    app()->instance(GeminiService::class, $gemini);

    $chatwoot = Mockery::mock(ChatwootApiService::class);
    $chatwoot->shouldNotReceive('send');
    app()->instance(ChatwootApiService::class, $chatwoot);

    BotConversation::create([
        'chatwoot_conversation_id' => 99,
        'state' => 'handoff',
        'phone' => '+502',
        'name' => 'Test',
    ]);

    app(ProcessChatwootMessageJob::class, [
        'chatwootConvId' => 99,
        'text' => 'Hola',
        'phone' => '+502',
        'name' => 'Test',
    ])->handle(app(GeminiService::class), app(ChatwootApiService::class));
});

it('job escalates to handoff when message is too long', function (): void {
    $chatwoot = Mockery::mock(ChatwootApiService::class);
    $chatwoot->shouldReceive('send')->once();
    $chatwoot->shouldReceive('handoff')->once();
    app()->instance(ChatwootApiService::class, $chatwoot);

    $gemini = Mockery::mock(GeminiService::class);
    $gemini->shouldNotReceive('classify');
    app()->instance(GeminiService::class, $gemini);

    app(ProcessChatwootMessageJob::class, [
        'chatwootConvId' => 100,
        'text' => str_repeat('a', 601),
        'phone' => '+502',
        'name' => 'Test',
    ])->handle(app(GeminiService::class), app(ChatwootApiService::class));

    expect(BotConversation::where('chatwoot_conversation_id', 100)->value('state'))->toBe('handoff');
});

// ---------------------------------------------------------------------------
// Job — routing by category
// ---------------------------------------------------------------------------

it('job sends flow button when category is agendar', function (): void {
    $gemini = Mockery::mock(GeminiService::class);
    $gemini->shouldReceive('classify')->once()->andReturn(['categoria' => 'agendar', 'mensaje' => 'Con gusto.']);
    app()->instance(GeminiService::class, $gemini);

    $chatwoot = Mockery::mock(ChatwootApiService::class);
    $chatwoot->shouldReceive('sendFlowButton')->once();
    app()->instance(ChatwootApiService::class, $chatwoot);

    app(ProcessChatwootMessageJob::class, [
        'chatwootConvId' => 101,
        'text' => 'quiero agendar',
        'phone' => '+502',
        'name' => 'Ana',
    ])->handle(app(GeminiService::class), app(ChatwootApiService::class));
});

it('job calls handoff when category is agente', function (): void {
    $gemini = Mockery::mock(GeminiService::class);
    $gemini->shouldReceive('classify')->once()->andReturn(['categoria' => 'agente', 'mensaje' => 'Te conecto.']);
    app()->instance(GeminiService::class, $gemini);

    $chatwoot = Mockery::mock(ChatwootApiService::class);
    $chatwoot->shouldReceive('send')->once();
    $chatwoot->shouldReceive('handoff')->once();
    app()->instance(ChatwootApiService::class, $chatwoot);

    app(ProcessChatwootMessageJob::class, [
        'chatwootConvId' => 102,
        'text' => 'quiero hablar con alguien',
        'phone' => '+502',
        'name' => 'Pedro',
    ])->handle(app(GeminiService::class), app(ChatwootApiService::class));

    expect(BotConversation::where('chatwoot_conversation_id', 102)->value('state'))->toBe('handoff');
});

it('job saves bot message to database', function (): void {
    $gemini = Mockery::mock(GeminiService::class);
    $gemini->shouldReceive('classify')->once()->andReturn(['categoria' => 'saludo', 'mensaje' => '¡Hola! ¿En qué puedo ayudarte?']);
    app()->instance(GeminiService::class, $gemini);

    $chatwoot = Mockery::mock(ChatwootApiService::class);
    $chatwoot->shouldReceive('send')->once();
    app()->instance(ChatwootApiService::class, $chatwoot);

    app(ProcessChatwootMessageJob::class, [
        'chatwootConvId' => 103,
        'text' => 'Hola',
        'phone' => '+502',
        'name' => 'María',
    ])->handle(app(GeminiService::class), app(ChatwootApiService::class));

    $conv = BotConversation::where('chatwoot_conversation_id', 103)->first();

    expect(BotMessage::where('bot_conversation_id', $conv->id)->where('role', 'bot')->count())->toBe(1)
        ->and(BotMessage::where('bot_conversation_id', $conv->id)->where('role', 'user')->count())->toBe(1);
});
