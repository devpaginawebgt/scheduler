<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChatwootMessageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatwootBotController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        Log::debug('Chatwoot webhook received', [
            'event' => $payload['event'] ?? null,
            'message_type' => $payload['message_type'] ?? null,
            'private' => $payload['private'] ?? null,
            'content' => mb_substr($payload['content'] ?? '', 0, 100),
            'conv_id' => $payload['conversation']['id'] ?? null,
            'sender' => $payload['sender']['name'] ?? null,
        ]);

        $skipReason = $this->skipReason($payload);

        if ($skipReason !== null) {
            return response()->json(['ok' => true]);
        }

        $deliveryId = $request->header('X-Chatwoot-Delivery') ?? uniqid('cw_', true);

        if (! Cache::add("chatwoot_delivery_{$deliveryId}", true, 3600)) {
            Log::debug('Chatwoot webhook duplicate', ['delivery_id' => $deliveryId]);

            return response()->json(['ok' => true]);
        }

        $chatwootConvId = (int) ($payload['conversation']['id'] ?? 0);
        $text = $payload['content'] ?? '';
        $phone = $payload['sender']['phone_number'] ?? '';
        $name = $payload['sender']['name'] ?? '';

        Log::info('Chatwoot webhook dispatching job', [
            'conv_id' => $chatwootConvId,
            'sender' => $name,
            'content' => mb_substr($text, 0, 100),
        ]);

        // El middleware RunAfterResponse ejecuta este callback DESPUÉS de enviar el 200.
        $request->attributes->set('_after_response', function () use ($chatwootConvId, $text, $phone, $name): void {
            ProcessChatwootMessageJob::dispatch($chatwootConvId, $text, $phone, $name);
        });

        return response()->json(['ok' => true]);
    }

    private function skipReason(array $payload): ?string
    {
        if (($payload['event'] ?? '') !== 'message_created') {
            return 'event_not_message_created: '.($payload['event'] ?? 'null');
        }

        if (($payload['message_type'] ?? '') !== 'incoming') {
            return 'message_type_not_incoming: '.($payload['message_type'] ?? 'null');
        }

        if ($payload['private'] ?? false) {
            return 'private_message';
        }

        if (empty($payload['content'])) {
            return 'empty_content';
        }

        return null;
    }
}
