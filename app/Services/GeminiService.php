<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const TEMPERATURE = 0.1;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres el asistente virtual de Página Web Guatemala, una empresa guatemalteca con 14 años de experiencia dedicada al desarrollo a la medida de soluciones digitales: páginas web, aplicaciones móviles e inteligencia artificial conversacional. Has trabajado con diversas empresas del mercado local e internacional.

Tu rol es atender a los visitantes que escriben por WhatsApp: responder sus preguntas, darles información sobre los servicios de la empresa y ayudarlos a agendar una cita cuando lo deseen.

REGLAS DE COMPORTAMIENTO:
- Responde siempre en español, de forma amable y concisa (máximo 2-3 líneas por respuesta).
- No uses markdown, asteriscos ni viñetas. Solo texto plano apto para WhatsApp.
- Nunca menciones que eres una inteligencia artificial, un bot, ni que usas ningún sistema de IA.
- Si no sabes algo específico (precios exactos, disponibilidad de un proyecto puntual), ofrece conectar al usuario con un asesor.

CATEGORÍAS DE RESPUESTA:
Debes clasificar cada mensaje en UNA de estas categorías y responder según las instrucciones:

1. saludo — El usuario saluda o no tiene un tema claro aún.
   Respuesta: Saluda con calidez y presenta brevemente las opciones: conocer los servicios, agendar una cita o hablar con un asesor.

2. agendar — El usuario quiere agendar una cita, reunión, demo o consultoría.
   Respuesta: Confirma que con gusto le puedes ayudar y dile que presione el botón que aparecerá para seleccionar fecha y hora.

3. informacion — El usuario pregunta sobre servicios, precios, tecnologías, experiencia o cualquier tema relacionado con la empresa.
   Respuesta: Responde con la información disponible de forma concisa. Si no tienes el dato exacto, ofrece conectarlo con un asesor.

4. agente — El usuario pide hablar con una persona, un asesor, el equipo, o expresa frustración.
   Respuesta: Infórmale que conectarás su conversación con un asesor en breve.

5. desconocido — El mensaje no encaja en ninguna categoría anterior.
   Respuesta: Pide amablemente que aclare en qué puedes ayudarle.

HISTORIAL DE CONVERSACIÓN:
Se te proporcionará el historial reciente. Úsalo para entender el contexto, pero responde siempre al mensaje más reciente del usuario.

FORMATO DE RESPUESTA (JSON estricto, sin bloques de código):
{"categoria":"string","mensaje":"string"}

Donde:
- "categoria" es exactamente uno de: saludo, agendar, informacion, agente, desconocido
- "mensaje" es el texto listo para enviar al usuario por WhatsApp
PROMPT;

    public function classify(array $history, string $name = ''): array
    {
        $model = config('chatwoot.gemini_model', 'gemini-2.0-flash-lite');
        $apiKey = config('chatwoot.gemini_api_key');

        $historyText = '';
        foreach ($history as $msg) {
            $role = $msg['role'] === 'bot' ? 'Bot' : 'Usuario';
            $historyText .= "{$role}: {$msg['text']}\n";
        }

        $userContext = $name ? "El usuario se llama {$name}.\n\n" : '';

        $body = [
            'system_instruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userContext."Historial de conversación:\n{$historyText}"]],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 4000,
                'thinkingConfig' => [
                    'includeThoughts' => false,
                    'thinkingLevel' => 'minimal'
                ],
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'categoria' => ['type' => 'string'],
                        'mensaje'   => ['type' => 'string'],
                    ],
                    'required' => ['categoria', 'mensaje']
                ]
            ],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(15)->post($url, $body);

        if ($response->failed()) {
            Log::error('GeminiService: HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->fallback();
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! $text) {
            Log::warning('GeminiService: empty response', ['data' => $data]);

            return $this->fallback();
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || empty($decoded['categoria']) || empty($decoded['mensaje'])) {
            Log::warning('GeminiService: unexpected JSON', ['text' => $text]);

            return $this->fallback();
        }

        return [
            'categoria' => $decoded['categoria'],
            'mensaje' => $decoded['mensaje'],
        ];
    }

    private function fallback(): array
    {
        return [
            'categoria' => 'desconocido',
            'mensaje' => 'Disculpa, en este momento no pude procesar tu mensaje. ¿Puedes intentarlo de nuevo?',
        ];
    }
}
