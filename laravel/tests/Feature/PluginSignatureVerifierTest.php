<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Core\\Plugins\\PluginSignatureVerifier;
use RuntimeException;
use Tests\\TestCase;

class PluginSignatureVerifierTest extends TestCase
{
    public function test_valid_ed25519_signature_verifies_package_identity_and_checksums(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP sodium extension is required.');
        }

        $root = sys_get_temp_dir() . '/aec-plugin-signature-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            $manifest = json_encode(['slug' => 'signed-test-plugin', 'version' => '1.2.3'], JSON_THROW_ON_ERROR);
            $checksums = json_encode(['plugin.json' => hash('sha256', $manifest)], JSON_THROW_ON_ERROR);
            file_put_contents($root . '/plugin.json', $manifest);
            file_put_contents($root . '/checksums.json', $checksums);

            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
            $message = implode("\\n", [
                'AEC-LMS-PLUGIN-SIGNATURE-V1',
                'signed-test-plugin',
                '1.2.3',
                hash('sha256', $checksums),
                hash('sha256', $manifest),
            ]);
            $signature = sodium_crypto_sign_detached($message, $secret);
            $signaturePath = $root . '/signature.json';
            file_put_contents($signaturePath, json_encode([
                'key_id' => 'test-release',
                'signature' => base64_encode($signature),
            ], JSON_THROW_ON_ERROR));

            config(['plugins.signature_keys' => ['test-release' => base64_encode($public)]]);
            $result = app(PluginSignatureVerifier::class)->verify($root, $signaturePath);

            $this->assertSame('test-release', $result['key_id']);
            $this->assertSame('Ed25519', $result['algorithm']);
            $this->assertSame(hash('sha256', $checksums), $result['checksums_sha256']);
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }
    }

    public function test_unknown_signing_key_is_rejected(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP sodium extension is required.');
        }

        $root = sys_get_temp_dir() . '/aec-plugin-signature-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            file_put_contents($root . '/plugin.json', json_encode(['slug' => 'x', 'version' => '1.0.0']));
            file_put_contents($root . '/checksums.json', '{}');
            $signaturePath = $root . '/signature.json';
            file_put_contents($signaturePath, json_encode(['key_id' => 'untrusted', 'signature' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES))]));
            config(['plugins.signature_keys' => []]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unknown or untrusted');
            app(PluginSignatureVerifier::class)->verify($root, $signaturePath);
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }
    }
}
