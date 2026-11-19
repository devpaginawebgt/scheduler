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

    private const INFO_MESSAGE = <<<'TEXT'
Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris.

¿Te gustaría agendar una reunión para conocer más sobre cómo implementarlo en tu empresa?
TEXT;

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
            $chatwoot->send($this->chatwootConvId, 'Para continuar ayudándote con tu solicitud, te pondré en contacto con uno de nuestros agentes.');
            $chatwoot->handoff($this->chatwootConvId, 'Mensaje demasiado largo');
            $conversation->update(['state' => 'handoff']);

            return;
        }

        $botMsgCount = $conversation->messages()->where('role', 'bot')->count();

        if ($botMsgCount >= self::MAX_BOT_MSGS) {
            $chatwoot->send($this->chatwootConvId, 'Para dar seguimiento a tu solicitud, te comunicaré con uno de nuestros agentes.');
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

        $savedText = $mensaje;

        if ($categoria === 'agente') {
            $chatwoot->send($this->chatwootConvId, $mensaje);
            $chatwoot->handoff($this->chatwootConvId);
            $conversation->update(['state' => 'handoff']);
        } elseif ($categoria === 'agendar') {
            $chatwoot->sendFlowButton($this->chatwootConvId, $this->phone);
        } elseif ($categoria === 'saludo') {
            $chatwoot->send($this->chatwootConvId, $mensaje);
        } elseif ($categoria === 'informacion') {
            $chatwoot->send($this->chatwootConvId, self::INFO_MESSAGE);
            $savedText = self::INFO_MESSAGE;
        } else {
            $chatwoot->send($this->chatwootConvId, $mensaje);
        }

        BotMessage::create([
            'bot_conversation_id' => $conversation->id,
            'role' => 'bot',
            'text' => $savedText,
            'category' => $categoria,
        ]);
    }
}
