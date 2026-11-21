<?php

declare(strict_types=1);

namespace Plugins\Modules\LtiPlatform\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LtiPlatformService
{
    public function ensureSigningKey(): object
    {
        $active = DB::table('lti_platform_keys')->where('is_active', true)->first();
        if ($active) {
            return $active;
        }

        return DB::transaction(function (): object {
            $active = DB::table('lti_platform_keys')->where('is_active', true)->lockForUpdate()->first();
            if ($active) {
                return $active;
            }

            $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            if ($resource === false || !openssl_pkey_export($resource, $privatePem)) {
                throw new RuntimeException('Unable to initialize the LTI platform signing key.');
            }
            $details = openssl_pkey_get_details($resource);
            if (!$details || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
                throw new RuntimeException('Unable to derive the LTI platform public key.');
            }

            $jwk = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => substr(hash('sha256', $details['key']), 0, 32),
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ];
            $now = now();
            $id = DB::table('lti_platform_keys')->insertGetId([
                'key_id' => $jwk['kid'],
                'private_key_encrypted' => Crypt::encryptString($privatePem),
                'public_jwk' => json_encode($jwk, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('lti_platform_keys')->where('id', $id)->first();
        }, 3);
    }

    public function publicJwks(): array
    {
        $this->ensureSigningKey();

        return [
            'keys' => DB::table('lti_platform_keys')
                ->where(function ($query): void {
                    $query->where('is_active', true)->orWhere('retire_after', '>', now());
                })
                ->orderByDesc('is_active')
                ->get(['public_jwk'])
                ->map(fn ($row) => json_decode($row->public_jwk, true, 512, JSON_THROW_ON_ERROR))
                ->values()->all(),
        ];
    }

    public function signPlatformToken(array $claims): string
    {
        $key = $this->ensureSigningKey();
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key->key_id];
        $input = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $privatePem = Crypt::decryptString($key->private_key_encrypted);
        if (!openssl_sign($input, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the LTI platform token.');
        }

        return $input . '.' . $this->base64UrlEncode($signature);
    }

    public function verifyToolAssertion(string $jwt, array $jwks, string $clientId): array
    {
        $parts = explode('.', $jwt);
        abort_unless(count($parts) === 3, 401, 'Malformed client assertion.');
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        abort_unless(is_array($header) && is_array($claims) && ($header['alg'] ?? null) === 'RS256'
            && is_string($header['kid'] ?? null), 401, 'Unsupported client assertion.');
        $jwk = collect($jwks['keys'] ?? [])->first(fn ($key) => ($key['kid'] ?? null) === $header['kid']
            && ($key['kty'] ?? null) === 'RSA' && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? 'RS256') === 'RS256' && isset($key['n'], $key['e']));
        abort_unless($jwk, 401, 'Unknown tool signing key.');
        $publicKey = $this->rsaJwkToPem($jwk);
        $valid = openssl_verify($parts[0] . '.' . $parts[1], $this->base64UrlDecode($parts[2]), $publicKey, OPENSSL_ALGO_SHA256);
        abort_unless($valid === 1, 401, 'Invalid client assertion signature.');

        $now = time();
        abort_unless(($claims['iss'] ?? null) === $clientId && ($claims['sub'] ?? null) === $clientId, 401, 'Invalid client assertion subject.');
        abort_unless(($claims['aud'] ?? null) === url('/lti/platform/token'), 401, 'Invalid client assertion audience.');
        abort_unless(is_int($claims['exp'] ?? null) && $claims['exp'] >= $now && $claims['exp'] <= $now + 300, 401, 'Expired or excessive client assertion lifetime.');
        abort_unless(is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + 30 && $claims['iat'] >= $now - 300, 401, 'Invalid client assertion issue time.');
        abort_unless(is_string($claims['jti'] ?? null) && strlen($claims['jti']) >= 16 && strlen($claims['jti']) <= 200, 401, 'Invalid client assertion identifier.');

        return $claims;
    }

    private function rsaJwkToPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);
        $rsa = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03" . $this->asn1Length(strlen($rsa) + 1) . "\x00" . $rsa;
        $der = $this->asn1Sequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1, 401, 'Invalid base64url data.');
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        abort_unless($decoded !== false, 401, 'Invalid base64url data.');

        return $decoded;
    }
}
