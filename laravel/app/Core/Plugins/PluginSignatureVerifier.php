<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use RuntimeException;

class PluginSignatureVerifier
{
    /**
     * Verify a detached Ed25519 signature over canonical package metadata.
     *
     * The signature file is JSON: {"key_id":"release-2026","signature":"<base64>"}.
     * The signed message deliberately excludes the ZIP bytes and instead binds the
     * package identity to the exact checksums and manifest content.
     *
     * @return array{key_id:string,algorithm:string,verified_at:string,checksums_sha256:string}
     */
    public function verify(string $packageRoot, string $signaturePath): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Ed25519 signature verification is unavailable: PHP sodium extension is required.');
        }

        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'plugin.json';
        $checksumsPath = $packageRoot . DIRECTORY_SEPARATOR . 'checksums.json';
        if (!is_file($manifestPath) || !is_file($checksumsPath) || !is_file($signaturePath)) {
            throw new RuntimeException('Signed plugin upload requires plugin.json, checksums.json and a detached signature file.');
        }

        try {
            $signatureData = json_decode((string) file_get_contents($signaturePath), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Plugin signature file is not valid JSON.', 0, $exception);
        }

        $keyId = $signatureData['key_id'] ?? null;
        $encodedSignature = $signatureData['signature'] ?? null;
        if (!is_string($keyId) || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $keyId) !== 1
            || !is_string($encodedSignature)) {
            throw new RuntimeException('Plugin signature metadata is invalid.');
        }

        $trustedKeys = config('plugins.signature_keys', []);
        $encodedPublicKey = is_array($trustedKeys) ? ($trustedKeys[$keyId] ?? null) : null;
        if (!is_string($encodedPublicKey) || $encodedPublicKey === '') {
            throw new RuntimeException('Plugin signature uses an unknown or untrusted signing key.');
        }

        $publicKey = base64_decode($encodedPublicKey, true);
        $signature = base64_decode($encodedSignature, true);
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Plugin signature or trusted public key has an invalid encoding.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_string($manifest['slug'] ?? null)
            || !is_string($manifest['version'] ?? null)) {
            throw new RuntimeException('Plugin manifest identity is invalid for signature verification.');
        }

        $checksumsHash = hash_file('sha256', $checksumsPath);
        $manifestHash = hash_file('sha256', $manifestPath);
        if (!is_string($checksumsHash) || !is_string($manifestHash)) {
            throw new RuntimeException('Plugin package metadata could not be hashed.');
        }

        $message = implode("\n", [
            'AEC-LMS-PLUGIN-SIGNATURE-V1',
            $manifest['slug'],
            $manifest['version'],
            $checksumsHash,
            $manifestHash,
        ]);

        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new RuntimeException('Plugin package signature verification failed.');
        }

        return [
            'key_id' => $keyId,
            'algorithm' => 'Ed25519',
            'verified_at' => now()->toIso8601String(),
            'checksums_sha256' => $checksumsHash,
        ];
    }
}
