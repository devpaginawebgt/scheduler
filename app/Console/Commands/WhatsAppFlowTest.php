<?php

namespace App\Console\Commands;

use App\Services\FlowCrypto;
use Illuminate\Console\Command;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use RuntimeException;

/**
 * Manual smoke-test for the WhatsApp Flow endpoint.
 *
 * Usage:
 *   php artisan whatsapp:flow-test ping
 *   php artisan whatsapp:flow-test INIT
 *   php artisan whatsapp:flow-test ir_a_disponibilidad
 *   php artisan whatsapp:flow-test consultar_horas --fecha=2026-11-17
 *   php artisan whatsapp:flow-test BACK
 */
class WhatsAppFlowTest extends Command
{
    protected $signature = 'whatsapp:flow-test
                            {accion : ping | INIT | ir_a_disponibilidad | consultar_horas | BACK}
                            {--fecha= : Date (YYYY-MM-DD) for consultar_horas}';

    protected $description = 'Send a test WhatsApp Flow request to the local endpoint and print the decrypted response.';

    public function handle(FlowCrypto $crypto): int
    {
        $accion = $this->argument('accion');
        $fecha = $this->option('fecha');

        [$privateKeyPem, $publicKeyPem] = $this->generateKeyPair();

        // Temporarily override config to use the in-memory key
        $tmpKeyPath = $this->writeTempKey($privateKeyPem);
        config(['whatsapp.flow_private_key_path' => $tmpKeyPath]);
        config(['whatsapp.flow_private_key_passphrase' => null]);
        config(['whatsapp.meta_app_secret' => 'test-secret']);

        try {
            $payload = $this->buildPayload($accion, $fecha);
            [$body, $aesKey, $iv] = $this->encryptPayload($payload, $publicKeyPem);

            $rawBody = json_encode($body);
            $signature = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

            $response = $this->sendRequest($rawBody, $signature);

            $decrypted = $crypto->decryptWithKey($response, $aesKey, $iv);

            $this->info('Response:');
            $this->line(json_encode($decrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmpKeyPath);
        }

        return self::SUCCESS;
    }

    private function buildPayload(string $accion, ?string $fecha): array
    {
        return match ($accion) {
            'ping' => ['action' => 'ping', 'screen' => '', 'data' => [], 'flow_token' => 'test'],
            'INIT' => ['action' => 'INIT', 'screen' => '', 'data' => [], 'flow_token' => 'test'],
            'ir_a_disponibilidad' => [
                'action' => 'data_exchange',
                'screen' => 'DATOS_EMPRESA',
                'data' => [
                    'accion' => 'ir_a_disponibilidad',
                    'empresa' => 'Empresa Test',
                    'mensajes' => 'Hola',
                    'periodo' => 'mes',
                ],
                'flow_token' => 'test',
            ],
            'consultar_horas' => [
                'action' => 'data_exchange',
                'screen' => 'DISPONIBILIDAD',
                'data' => [
                    'accion' => 'consultar_horas',
                    'fecha' => $fecha ?? now(config('whatsapp.flow_timezone'))->addWeekdays(1)->toDateString(),
                ],
                'flow_token' => 'test',
            ],
            'BACK' => [
                'action' => 'BACK',
                'screen' => 'DISPONIBILIDAD',
                'data' => [],
                'flow_token' => 'test',
            ],
            default => throw new RuntimeException("Acción desconocida: {$accion}"),
        };
    }

    /** @return array{0: string, 1: string} [encryptedFlowData, encryptedAesKey, initialVector] */
    private function encryptPayload(array $payload, string $publicKeyPem): array
    {
        // Generate random 16-byte AES key and IV
        $aesKey = random_bytes(16);
        $iv = random_bytes(12);

        // Encrypt payload with AES-128-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($payload),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );

        $encryptedFlowData = base64_encode($ciphertext.$tag);

        // Encrypt AES key with RSA-OAEP SHA-256
        /** @var PublicKey $pub */
        $pub = RSA::loadPublicKey($publicKeyPem);
        $encryptedAesKey = base64_encode(
            $pub->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($aesKey)
        );

        $initialVector = base64_encode($iv);

        return [
            ['encrypted_flow_data' => $encryptedFlowData, 'encrypted_aes_key' => $encryptedAesKey, 'initial_vector' => $initialVector],
            $aesKey,
            $initialVector,
        ];
    }

    private function sendRequest(string $body, string $signature): string
    {
        $url = config('app.url').'/api/whatsapp/flows';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Hub-Signature-256: '.$signature,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("Request failed with HTTP {$httpCode}.");
        }

        return (string) $response;
    }

    /** @return array{0: string, 1: string} [privatePem, publicPem] */
    private function generateKeyPair(): array
    {
        $private = RSA::createKey(2048);
        /** @var PublicKey $public */
        $public = $private->getPublicKey();

        return [(string) $private, (string) $public];
    }

    private function writeTempKey(string $pem): string
    {
        $path = storage_path('keys/flow_private_test.pem');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $pem);

        return $path;
    }
}
