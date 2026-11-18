<?php

declare(strict_types=1);

/**
 * Offline Ed25519 signing helper for Alom's English Center LMS plugin ZIPs.
 * Run only from a trusted CLI workstation. Never copy private keys to the LMS host.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "PHP sodium extension is required.\n");
    exit(1);
}

function usage(): never
{
    fwrite(STDERR, <<<TXT
Usage:
  php tools/plugin-signing.php generate <key-directory>
  php tools/plugin-signing.php sign <plugin-directory> <key-id> <private-key-file> <signature-output.json>

Private keys are stored as base64-encoded Ed25519 secret keys. Keep them offline.
The signature JSON is uploaded separately from the plugin ZIP in Plugin Center.

TXT);
    exit(2);
}

function writeNewFile(string $path, string $contents, int $mode): void
{
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException("Refusing to overwrite an existing file: {$path}");
    }
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException("Unable to create directory: {$parent}");
    }
    $handle = fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException("Unable to create file: {$path}");
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException("Unable to write complete file: {$path}");
        }
    } finally {
        fclose($handle);
    }
    chmod($path, $mode);
}

function validatePackage(string $directory): array
{
    $root = realpath($directory);
    if ($root === false || !is_dir($root) || is_link($directory)) {
        throw new RuntimeException('Plugin directory must be a real, non-symlink directory.');
    }

    $manifestPath = $root . DIRECTORY_SEPARATOR . 'plugin.json';
    $checksumsPath = $root . DIRECTORY_SEPARATOR . 'checksums.json';
    foreach ([$manifestPath, $checksumsPath] as $path) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Plugin directory must contain regular plugin.json and checksums.json files.');
        }
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $checksumsRaw = (string) file_get_contents($checksumsPath);
    $checksums = json_decode($checksumsRaw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_string($manifest['slug'] ?? null)
        || preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $manifest['slug']) !== 1
        || !is_string($manifest['version'] ?? null) || $manifest['version'] === ''
        || !is_array($checksums) || !isset($checksums['plugin.json'])) {
        throw new RuntimeException('Plugin manifest identity or checksums.json is invalid.');
    }

    $verified = [];
    foreach ($checksums as $relative => $expected) {
        if (!is_string($relative) || !is_string($expected)
            || preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1
            || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative) === 1
            || in_array('..', explode('/', $relative), true)
            || in_array('.', explode('/', $relative), true)
            || in_array($relative, ['checksums.json', '.aec-plugin-signature.json'], true)) {
            throw new RuntimeException('Unsafe path or hash found in checksums.json.');
        }

        $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($file);
        if ($resolved === false || !is_file($resolved) || is_link($file)
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Missing or unsafe plugin file: {$relative}");
        }
        $actual = hash_file('sha256', $resolved);
        if (!is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) {
            throw new RuntimeException("SHA-256 mismatch: {$relative}");
        }
        $verified[$relative] = true;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Symlinks are not allowed in a signed plugin package.');
        }
        if (!$entry->isFile()) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
        if (in_array($relative, ['checksums.json', '.aec-plugin-signature.json'], true)) {
            continue;
        }
        if (!isset($verified[$relative])) {
            throw new RuntimeException("Unchecksummed plugin file: {$relative}");
        }
    }

    return [
        'slug' => $manifest['slug'],
        'version' => $manifest['version'],
        'checksums_sha256' => hash('sha256', $checksumsRaw),
        'manifest_sha256' => hash_file('sha256', $manifestPath),
    ];
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'generate') {
        if (count($argv) !== 3) usage();
        $directory = rtrim($argv[2], DIRECTORY_SEPARATOR);
        if ($directory === '' || file_exists($directory)) {
            throw new RuntimeException('Choose a new, empty key directory path.');
        }
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('Unable to create private key directory.');
        }
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
        $public = base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
        writeNewFile($directory . DIRECTORY_SEPARATOR . 'plugin-release-private.key', $secret, 0600);
        writeNewFile($directory . DIRECTORY_SEPARATOR . 'plugin-release-public.key', $public, 0644);
        fwrite(STDOUT, "Key pair created.\nPrivate key: {$directory}/plugin-release-private.key (keep offline)\nPublic key: {$directory}/plugin-release-public.key\n");
        exit(0);
    }

    if ($action === 'sign') {
        if (count($argv) !== 6) usage();
        [, , $pluginDirectory, $keyId, $privateKeyPath, $outputPath] = $argv;
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/', $keyId) !== 1) {
            throw new RuntimeException('Key ID may contain only letters, digits, dot, underscore and hyphen.');
        }
        if (!is_file($privateKeyPath) || is_link($privateKeyPath)) {
            throw new RuntimeException('Private key file is missing or unsafe.');
        }
        $outputParent = realpath(dirname($outputPath));
        $pluginRoot = realpath($pluginDirectory);
        if ($outputParent === false || $pluginRoot === false
            || str_starts_with($outputParent . DIRECTORY_SEPARATOR . basename($outputPath), $pluginRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Signature output must be outside the plugin package directory.');
        }

        $secret = base64_decode(trim((string) file_get_contents($privateKeyPath)), true);
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Private key file does not contain a valid base64 Ed25519 secret key.');
        }
        $package = validatePackage($pluginDirectory);
        $message = implode("\n", [
            'AEC-LMS-PLUGIN-SIGNATURE-V1',
            $package['slug'],
            $package['version'],
            $package['checksums_sha256'],
            $package['manifest_sha256'],
        ]);
        $signature = sodium_crypto_sign_detached($message, $secret);
        $document = json_encode([
            'key_id' => $keyId,
            'signature' => base64_encode($signature),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        writeNewFile($outputPath, $document, 0600);
        fwrite(STDOUT, "Signed {$package['slug']} v{$package['version']} with key {$keyId}.\nSignature sidecar: {$outputPath}\n");
        exit(0);
    }

    usage();
} catch (Throwable $exception) {
    fwrite(STDERR, "Signing failed: " . $exception->getMessage() . "\n");
    exit(1);
}
