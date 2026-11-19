<?php

namespace App\Jobs;

use App\Models\BotConversation;
use App\Models\BotMessage;
use App\Services\ChatwootApiService;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChatwootMessageJob implements ShouldQueue
{
    use Queueable;

    private const HISTORY_MAX = 12;

    private const MAX_MSG_LEN = 600;

    private const MAX_BOT_MSGS = 10;

    public function __construct(
        private readonly int $chatwootConvId,
        private readonly string $text,
        private readonly string $phone,
        private readonly string $name,
    ) {}

    public function handle(GeminiService $gemini, ChatwootApiService $chatwoot): void
    {
        $conversation = BotConversation::firstOrCreate(
            ['chatwoot_conversation_id' => $this->chatwootConvId],
            ['phone' => $this->phone, 'name' => $this->name, 'state' => 'bot'],
        );

        if ($conversation->isHandedOff()) {
            return;
        }

        if (mb_strlen($this->text) > self::MAX_MSG_LEN) {
            $chatwoot->send($this->chatwootConvId, 'Tu mensaje es muy largo para procesarlo automáticamente. Te conectaré con un asesor.');
            $chatwoot->handoff($this->chatwootConvId, 'Mensaje demasiado largo');
            $conversation->update(['state' => 'handoff']);

            return;
        }

        $botMsgCount = $conversation->messages()->where('role', 'bot')->count();

        if ($botMsgCount >= self::MAX_BOT_MSGS) {
            $chatwoot->send($this->chatwootConvId, 'Ha llegado el límite de esta conversación automática. Te conecto con un asesor.');
            $chatwoot->handoff($this->chatwootConvId, 'Límite de mensajes alcanzado');
            $conversation->update(['state' => 'handoff']);

            return;
        }

        BotMessage::create([
            'bot_conversation_id' => $conversation->id,
            'role' => 'user',
            'text' => $this->text,
        ]);

        $history = $conversation->messages()
            ->latest()
            ->limit(self::HISTORY_MAX)
            ->get()
            ->reverse()
            ->map(fn (BotMessage $m) => ['role' => $m->role, 'text' => $m->text])
            ->values()
            ->all();

        $result = $gemini->classify($history, $this->name);
        $categoria = $result['categoria'];
        $mensaje = $result['mensaje'];

        if ($categoria === 'agente') {
            $chatwoot->send($this->chatwootConvId, $mensaje);
            $chatwoot->handoff($this->chatwootConvId);
            $conversation->update(['state' => 'handoff']);
        } elseif ($categoria === 'agendar') {
            $chatwoot->send($this->chatwootConvId, $mensaje);
            $chatwoot->sendFlowButton($this->chatwootConvId, $this->phone);
        } else {
            $chatwoot->send($this->chatwootConvId, $mensaje);
        }

        BotMessage::create([
            'bot_conversation_id' => $conversation->id,
            'role' => 'bot',
            'text' => $mensaje,
            'category' => $categoria,
        ]);
    }
}
