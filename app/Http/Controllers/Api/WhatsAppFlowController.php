<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DisponibilidadServiceContract;
use App\Http\Controllers\Controller;
use App\Services\FlowCrypto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppFlowController extends Controller
{
    public function __construct(
        private readonly FlowCrypto $crypto,
        private readonly DisponibilidadServiceContract $disponibilidad,
    ) {}

    public function handle(Request $request): Response
    {
        $startedAt = hrtime(true);

        $body = $request->json()->all();

        $encryptedFlowData = $body['encrypted_flow_data'] ?? '';
        $encryptedAesKey = $body['encrypted_aes_key'] ?? '';
        $initialVector = $body['initial_vector'] ?? '';

        try {
            // Resolve AES key once; reuse it for both decryption and encryption.
            $aesKey = $this->crypto->resolveAesKey($encryptedAesKey);
            $payload = $this->crypto->decryptWithKey($encryptedFlowData, $aesKey, $initialVector);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp Flow: decryption failed', ['error' => $e->getMessage()]);

            return response('', 421);
        }

        $responsePayload = $this->dispatch($payload);

        $this->logRequest($payload, $responsePayload, $startedAt);

        $encrypted = $this->crypto->encryptWithKey($responsePayload, $aesKey, $initialVector);

        return response($encrypted, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $responsePayload
     */
    private function logRequest(array $payload, array $responsePayload, int $startedAt): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $responseData = is_array($responsePayload['data'] ?? null) ? $responsePayload['data'] : [];
        $errorMsg = $responseData['error_message'] ?? null;
        $isLocal = app()->environment('local');

        $context = [
            'action' => $payload['action'] ?? '',
            'screen' => $payload['screen'] ?? '',
            'accion' => $data['accion'] ?? null,
            'flow_token' => $payload['flow_token'] ?? '',
            'response_screen' => $responsePayload['screen'] ?? null,
            'error_message' => $errorMsg !== null
                ? ($isLocal ? $errorMsg : true)
                : null,
            'data_fields' => array_keys($data),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ];

        if ($isLocal && ! empty($data)) {
            $context['data_values'] = $data;
        }

        Log::info('WhatsApp Flow: request', $context);
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(array $payload): array
    {
        $action = $payload['action'] ?? '';
        $screen = $payload['screen'] ?? '';
        $data = $payload['data'] ?? [];
        $flowToken = $payload['flow_token'] ?? '';

        // Client-reported error
        if (isset($data['error'])) {
            Log::error('WhatsApp Flow: client reported error', [
                'flow_token' => $flowToken,
                'error' => $data['error'],
            ]);

            return ['data' => ['acknowledged' => true]];
        }

        return match ($action) {
            'ping' => $this->handlePing(),
            'INIT' => $this->handleInit(),
            'data_exchange' => $this->handleDataExchange($data),
            'BACK' => $this->handleBack($screen, $data),
            default => $this->handleUnknown($screen),
        };
    }

    private function handlePing(): array
    {
        return ['data' => ['status' => 'active']];
    }

    private function handleInit(): array
    {
        return ['screen' => 'DATOS_EMPRESA', 'data' => (object) []];
    }

    /** @param array<string, mixed> $data */
    private function handleDataExchange(array $data): array
    {
        $accion = $data['accion'] ?? '';

        return match ($accion) {
            'ir_a_disponibilidad' => $this->irADisponibilidad(),
            'consultar_horas' => $this->consultarHoras($data),
            default => $this->unknownAccion($data),
        };
    }

    private function irADisponibilidad(): array
    {
        $rango = $this->disponibilidad->rango();

        return [
            'screen' => 'DISPONIBILIDAD',
            'data' => [
                'min_fecha' => $rango['min_fecha'],
                'max_fecha' => $rango['max_fecha'],
                'fechas_bloqueadas' => $rango['fechas_bloqueadas'],
                'horas' => [],
                'mostrar_horas' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function consultarHoras(array $data): array
    {
        $fecha = $data['fecha'] ?? '';
        $rango = $this->disponibilidad->rango();
        $horas = $this->disponibilidad->horas($fecha);

        $responseData = [
            'min_fecha' => $rango['min_fecha'],
            'max_fecha' => $rango['max_fecha'],
            'fechas_bloqueadas' => $rango['fechas_bloqueadas'],
            'horas' => $horas,
            'mostrar_horas' => count($horas) > 0,
        ];

        if (empty($horas)) {
            $responseData['error_message'] = 'Ese día ya no tiene horarios disponibles, elige otro.';
        }

        return ['screen' => 'DISPONIBILIDAD', 'data' => $responseData];
    }

    /** @param array<string, mixed> $data */
    private function handleBack(string $screen, array $data): array
    {
        if ($screen === 'DISPONIBILIDAD') {
            $rango = $this->disponibilidad->rango();
            $fecha = $data['fecha'] ?? '';
            $horas = $fecha ? $this->disponibilidad->horas($fecha) : [];

            return [
                'screen' => 'DISPONIBILIDAD',
                'data' => [
                    'min_fecha' => $rango['min_fecha'],
                    'max_fecha' => $rango['max_fecha'],
                    'fechas_bloqueadas' => $rango['fechas_bloqueadas'],
                    'horas' => $horas,
                    'mostrar_horas' => count($horas) > 0,
                ],
            ];
        }

        return ['screen' => $screen, 'data' => (object) []];
    }

    private function handleUnknown(string $screen): array
    {
        Log::warning('WhatsApp Flow: unknown action received', ['screen' => $screen]);

        return [
            'screen' => $screen ?: 'DATOS_EMPRESA',
            'data' => ['error_message' => 'Ocurrió un error inesperado. Intenta de nuevo.'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function unknownAccion(array $data): array
    {
        $accion = $data['accion'] ?? 'unknown';
        Log::warning('WhatsApp Flow: unknown accion', ['accion' => $accion]);

        return [
            'screen' => 'DATOS_EMPRESA',
            'data' => ['error_message' => 'Ocurrió un error inesperado. Intenta de nuevo.'],
        ];
    }
}
