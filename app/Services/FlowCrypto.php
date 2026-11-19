<?php

namespace App\Services;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use RuntimeException;

class FlowCrypto
{
    /**
     * Decrypt an incoming WhatsApp Flow request body.
     *
     * Returns the decoded payload array or throws RuntimeException on failure.
     *
     * @return array{version: string, action: string, screen: string, data: array<string, mixed>, flow_token: string}
     */
    public function decrypt(string $encryptedFlowData, string $encryptedAesKey, string $initialVector): array
    {
        $aesKey = $this->resolveAesKey($encryptedAesKey);

        $encryptedData = base64_decode($encryptedFlowData);
        $iv = base64_decode($initialVector);

        // Last 16 bytes are the GCM tag
        $tag = substr($encryptedData, -16);
        $ciphertext = substr($encryptedData, 0, -16);

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($decrypted === false) {
            throw new RuntimeException('AES-128-GCM decryption failed.');
        }

        $payload = json_decode($decrypted, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Decrypted payload is not valid JSON.');
        }

        return $payload;
    }

    /**
     * Decrypt only the AES key (RSA-OAEP SHA-256), returning raw bytes.
     * Use together with encryptWithKey / decryptWithKey to avoid a second RSA round-trip.
     */
    public function resolveAesKey(string $encryptedAesKey): string
    {
        $rsa = $this->loadRsa();
        $aesKey = $rsa->decrypt(base64_decode($encryptedAesKey));

        if ($aesKey === false || $aesKey === '') {
            throw new RuntimeException('Failed to decrypt AES key.');
        }

        return $aesKey;
    }

    /**
     * Encrypt a response using a pre-decrypted raw AES key.
     * IV for encryption = ~initialVector (all bytes XOR 0xFF).
     * Returns a base64 string to send as text/plain.
     */
    public function encryptWithKey(array $response, string $aesKey, string $initialVector): string
    {
        $flippedIv = $this->flipBytes(base64_decode($initialVector));

        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) json_encode($response),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $flippedIv,
            $tag,
            '',
            16,
        );

        return base64_encode($ciphertext.$tag);
    }

    /**
     * Decrypt a WhatsApp Flow *response* using the same key.
     * Responses are encrypted with the flipped IV (~initialVector), so we flip it back here.
     *
     * @return array<string, mixed>
     */
    public function decryptResponseWithKey(string $encryptedResponse, string $aesKey, string $initialVector): array
    {
        $flippedIv = base64_encode($this->flipBytes(base64_decode($initialVector)));

        return $this->decryptWithKey($encryptedResponse, $aesKey, $flippedIv);
    }

    /**
     * Decrypt using a pre-loaded raw AES key (avoids RSA on each call during tests).
     *
     * @return array<string, mixed>
     */
    public function decryptWithKey(string $encryptedFlowData, string $aesKey, string $initialVector): array
    {
        $encryptedData = base64_decode($encryptedFlowData);
        $iv = base64_decode($initialVector);

        $tag = substr($encryptedData, -16);
        $ciphertext = substr($encryptedData, 0, -16);

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($decrypted === false) {
            throw new RuntimeException('AES-128-GCM decryption failed.');
        }

        $payload = json_decode($decrypted, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Decrypted payload is not valid JSON.');
        }

        return $payload;
    }

    private function loadRsa(): PrivateKey
    {
        $configPath = (string) config('whatsapp.flow_private_key_path');

        if ($configPath === '') {
            throw new RuntimeException('FLOW_PRIVATE_KEY_PATH is not configured.');
        }

        // Accept absolute paths (e.g. from tests) and paths relative to the project root.
        $privateKeyPath = is_file($configPath) ? $configPath : base_path($configPath);

        if (! is_file($privateKeyPath)) {
            throw new RuntimeException('Flow private key not found at: '.$privateKeyPath);
        }

        $privateKeyPem = file_get_contents($privateKeyPath);

        if ($privateKeyPem === false || $privateKeyPem === '') {
            throw new RuntimeException('Flow private key is empty or unreadable: '.$privateKeyPath);
        }

        // Normalize Windows line endings.
        $privateKeyPem = str_replace("\r\n", "\n", $privateKeyPem);

        try {
            /** @var PrivateKey $key */
            $key = RSA::loadPrivateKey($privateKeyPem);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to load RSA private key: '.$e->getMessage(), 0, $e);
        }

        return $key
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
    }

    private function flipBytes(string $bytes): string
    {
        $result = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $result .= chr(ord($bytes[$i]) ^ 0xFF);
        }

        return $result;
    }
}
