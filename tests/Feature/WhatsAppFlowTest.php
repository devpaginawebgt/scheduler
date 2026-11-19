<?php

use App\Contracts\DisponibilidadServiceContract;
use App\Services\FlowCrypto;
use App\Services\MockDisponibilidadService;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;

// ---------------------------------------------------------------------------
// Helpers shared across all tests in this file
// ---------------------------------------------------------------------------

/**
 * Generate a temporary RSA key pair and write the private key to storage.
 * Returns [privateKeyPem, publicKeyPem, tmpPath].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function generateTestKeyPair(): array
{
    /** @var PrivateKey $private */
    $private = RSA::createKey(2048);
    /** @var PublicKey $public */
    $public = $private->getPublicKey();

    $tmpPath = storage_path('keys/flow_private_test.pem');

    if (! is_dir(dirname($tmpPath))) {
        mkdir(dirname($tmpPath), 0700, true);
    }

    file_put_contents($tmpPath, (string) $private);

    return [(string) $private, (string) $public, $tmpPath];
}

/**
 * Encrypt a Flow payload the same way WhatsApp would before sending it.
 *
 * @return array{body: array{encrypted_flow_data: string, encrypted_aes_key: string, initial_vector: string}, aes_key: string, iv: string}
 */
function encryptFlowPayload(array $payload, string $publicKeyPem): array
{
    $aesKey = random_bytes(16);
    $iv = random_bytes(12);

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

    /** @var PublicKey $pub */
    $pub = RSA::loadPublicKey($publicKeyPem);
    $encryptedAesKey = base64_encode(
        $pub->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($aesKey)
    );

    $initialVector = base64_encode($iv);

    return [
        'body' => [
            'encrypted_flow_data' => base64_encode($ciphertext.$tag),
            'encrypted_aes_key' => $encryptedAesKey,
            'initial_vector' => $initialVector,
        ],
        'aes_key' => $aesKey,
        'iv' => $initialVector,
    ];
}

/**
 * Decrypt the text/plain response body the same way a Flow client would.
 * Uses the flipped IV because responses are encrypted with ~initialVector.
 *
 * @return array<string, mixed>
 */
function decryptFlowResponse(string $responseBody, string $aesKey, string $initialVector): array
{
    return app(FlowCrypto::class)->decryptResponseWithKey($responseBody, $aesKey, $initialVector);
}

/**
 * Post a signed & encrypted Flow request and return the raw response.
 */
function postFlow(array $payload, string $publicKeyPem, string $secret = 'test-secret'): TestResponse
{
    $encrypted = encryptFlowPayload($payload, $publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $signature = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

    return test()->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $signature,
    ])->withHeaders(['Content-Type' => 'application/json']);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->app->bind(DisponibilidadServiceContract::class, MockDisponibilidadService::class);

    [$this->privateKeyPem, $this->publicKeyPem, $this->tmpKeyPath] = generateTestKeyPair();

    config([
        'whatsapp.flow_private_key_path' => $this->tmpKeyPath,
        'whatsapp.flow_private_key_passphrase' => null,
        'whatsapp.meta_app_secret' => 'test-secret',
    ]);
});

afterEach(function (): void {
    @unlink($this->tmpKeyPath);
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('returns 432 when X-Hub-Signature-256 is missing', function (): void {
    $encrypted = encryptFlowPayload(['action' => 'ping', 'screen' => '', 'data' => [], 'flow_token' => 'tok'], $this->publicKeyPem);

    $this->postJson('/api/whatsapp/flows', $encrypted['body'])
        ->assertStatus(432);
});

it('returns 432 when signature is invalid', function (): void {
    $encrypted = encryptFlowPayload(['action' => 'ping', 'screen' => '', 'data' => [], 'flow_token' => 'tok'], $this->publicKeyPem);

    $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => 'sha256=badhash',
    ])->assertStatus(432);
});

it('returns 421 when encrypted_flow_data is tampered', function (): void {
    $encrypted = encryptFlowPayload(['action' => 'ping', 'screen' => '', 'data' => [], 'flow_token' => 'tok'], $this->publicKeyPem);

    $tampered = $encrypted['body'];
    $tampered['encrypted_flow_data'] = base64_encode('this is not valid ciphertext 123456');

    $rawBody = json_encode($tampered);
    $signature = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $this->postJson('/api/whatsapp/flows', $tampered, [
        'X-Hub-Signature-256' => $signature,
    ])->assertStatus(421);
});

// ---------------------------------------------------------------------------
// ping
// ---------------------------------------------------------------------------

it('responds active to ping', function (): void {
    $payload = ['action' => 'ping', 'screen' => '', 'data' => [], 'flow_token' => 'tok'];
    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted)->toBe(['data' => ['status' => 'active']]);
});

// ---------------------------------------------------------------------------
// INIT
// ---------------------------------------------------------------------------

it('responds with DATOS_EMPRESA screen on INIT', function (): void {
    $payload = ['action' => 'INIT', 'screen' => '', 'data' => [], 'flow_token' => 'tok'];
    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted['screen'])->toBe('DATOS_EMPRESA');
});

// ---------------------------------------------------------------------------
// data_exchange — ir_a_disponibilidad
// ---------------------------------------------------------------------------

it('returns DISPONIBILIDAD screen with rango on ir_a_disponibilidad', function (): void {
    $payload = [
        'action' => 'data_exchange',
        'screen' => 'DATOS_EMPRESA',
        'data' => [
            'accion' => 'ir_a_disponibilidad',
            'empresa' => 'Empresa Test',
            'mensajes' => 'Hola',
            'periodo' => 'mes',
        ],
        'flow_token' => 'tok',
    ];

    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted['screen'])->toBe('DISPONIBILIDAD')
        ->and($decrypted['data'])->toHaveKeys(['min_fecha', 'max_fecha', 'fechas_bloqueadas', 'horas', 'mostrar_horas'])
        ->and($decrypted['data']['horas'])->toBe([])
        ->and($decrypted['data']['mostrar_horas'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// data_exchange — consultar_horas (day WITH slots)
// ---------------------------------------------------------------------------

it('returns hours for a day that has available slots', function (): void {
    // Find the first available weekday that has slots
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
    $fecha = Carbon::now($tz)->addWeekdays(1)->toDateString();

    $payload = [
        'action' => 'data_exchange',
        'screen' => 'DISPONIBILIDAD',
        'data' => ['accion' => 'consultar_horas', 'fecha' => $fecha],
        'flow_token' => 'tok',
    ];

    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted['screen'])->toBe('DISPONIBILIDAD')
        ->and($decrypted['data']['mostrar_horas'])->toBeTrue()
        ->and($decrypted['data']['horas'])->not->toBeEmpty()
        ->and($decrypted['data'])->not->toHaveKey('error_message');
});

// ---------------------------------------------------------------------------
// data_exchange — consultar_horas (day WITHOUT slots / invalid)
// ---------------------------------------------------------------------------

it('returns error_message for a day with no slots', function (): void {
    // Saturday has no slots
    $tz = config('whatsapp.flow_timezone', 'America/Guatemala');
    $saturday = Carbon::now($tz)->next(Carbon::SATURDAY)->toDateString();

    $payload = [
        'action' => 'data_exchange',
        'screen' => 'DISPONIBILIDAD',
        'data' => ['accion' => 'consultar_horas', 'fecha' => $saturday],
        'flow_token' => 'tok',
    ];

    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted['screen'])->toBe('DISPONIBILIDAD')
        ->and($decrypted['data']['horas'])->toBe([])
        ->and($decrypted['data']['mostrar_horas'])->toBeFalse()
        ->and($decrypted['data'])->toHaveKey('error_message');
});

// ---------------------------------------------------------------------------
// BACK
// ---------------------------------------------------------------------------

it('returns current screen data on BACK', function (): void {
    $payload = [
        'action' => 'BACK',
        'screen' => 'DISPONIBILIDAD',
        'data' => [],
        'flow_token' => 'tok',
    ];

    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted['screen'])->toBe('DISPONIBILIDAD')
        ->and($decrypted['data'])->toHaveKeys(['min_fecha', 'max_fecha', 'fechas_bloqueadas', 'horas', 'mostrar_horas']);
});

// ---------------------------------------------------------------------------
// Client error notification
// ---------------------------------------------------------------------------

it('acknowledges a client-reported error', function (): void {
    $payload = [
        'action' => 'data_exchange',
        'screen' => 'DATOS_EMPRESA',
        'data' => ['error' => 'Something went wrong on the client'],
        'flow_token' => 'tok',
    ];

    $encrypted = encryptFlowPayload($payload, $this->publicKeyPem);
    $rawBody = json_encode($encrypted['body']);
    $sig = 'sha256='.hash_hmac('sha256', $rawBody, 'test-secret');

    $response = $this->postJson('/api/whatsapp/flows', $encrypted['body'], [
        'X-Hub-Signature-256' => $sig,
    ]);

    $response->assertStatus(200);

    $decrypted = decryptFlowResponse($response->getContent(), $encrypted['aes_key'], $encrypted['iv']);

    expect($decrypted)->toBe(['data' => ['acknowledged' => true]]);
});
