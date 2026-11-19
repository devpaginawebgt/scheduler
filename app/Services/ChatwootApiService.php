<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatwootApiService
{
    private function baseUrl(): string
    {
        $url = rtrim((string) config('chatwoot.url'), '/');
        $accountId = config('chatwoot.account_id');

        return "{$url}/api/v1/accounts/{$accountId}";
    }

    private function headers(): array
    {
        return [
            'api_access_token' => config('chatwoot.bot_token'),
            'Content-Type' => 'application/json',
        ];
    }

    public function send(int $chatwootConvId, string $text, bool $private = false): void
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl()}/conversations/{$chatwootConvId}/messages", [
                'content' => $text,
                'message_type' => 'outgoing',
                'private' => $private,
            ]);

        if ($response->failed()) {
            Log::error('ChatwootApiService: failed to send message', [
                'conv_id' => $chatwootConvId,
                'status' => $response->status(),
            ]);
        }
    }

    public function sendFlowButton(int $chatwootConvId, string $recipientPhone): void
    {
        $version = config('whatsapp.api_version', 'v21.0');
        $phoneNumberId = config('whatsapp.phone_number_id');
        $accessToken = config('whatsapp.access_token');
        $flowId = config('chatwoot.whatsapp_flow_id');

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $recipientPhone,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'flow',
                    'body' => ['text' => 'Selecciona una fecha y hora para tu cita.'],
                    'action' => [
                        'name' => 'flow',
                        'parameters' => [
                            'flow_message_version' => '3',
                            'flow_token' => 'conv_'.$chatwootConvId,
                            'flow_id' => $flowId,
                            'flow_cta' => 'Agendar cita',
                            'flow_action' => 'navigate',
                            'flow_action_payload' => ['screen' => 'DATOS_EMPRESA'],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('ChatwootApiService: failed to send flow button via WhatsApp API', [
                'conv_id' => $chatwootConvId,
                'phone' => $recipientPhone,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    public function handoff(int $chatwootConvId, ?string $note = null): void
    {
        if ($note) {
            $this->send($chatwootConvId, "Bot handoff: {$note}", true);
        }

        $this->addLabel($chatwootConvId, 'bot-handoff');

        Http::withHeaders($this->headers())
            ->timeout(10)
            ->patch("{$this->baseUrl()}/conversations/{$chatwootConvId}", [
                'status' => 'open',
            ]);
    }

    private function addLabel(int $chatwootConvId, string $label): void
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->get("{$this->baseUrl()}/conversations/{$chatwootConvId}/labels");

        $existing = $response->json('payload', []);
        $labels = array_unique([...$existing, $label]);

        Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl()}/conversations/{$chatwootConvId}/labels", [
                'labels' => $labels,
            ]);
    }
}
