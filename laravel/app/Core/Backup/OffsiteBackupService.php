<?php

declare(strict_types=1);

namespace App\Core\Backup;

use App\Models\BackupRecord;
use RuntimeException;

class OffsiteBackupService
{
    /** @return array{uploaded:int,verified:int,failed:int} */
    public function sync(bool $verifyDownloads = true): array
    {
        $config = $this->configuration();
        $result = ['uploaded' => 0, 'verified' => 0, 'failed' => 0];

        BackupRecord::query()->orderBy('id')->chunkById(50, function ($records) use ($config, $verifyDownloads, &$result): void {
            foreach ($records as $record) {
                try {
                    $path = (string) $record->storage_path;
                    $root = realpath((string) config('lms.backup_path'));
                    $real = realpath($path);
                    $localHash = $real ? hash_file('sha256', $real) : false;
                    if (!$root || !$real || is_link($path) || dirname($real) !== $root
                        || !is_file($real) || !is_string($localHash)
                        || !hash_equals((string) $record->checksum_sha256, $localHash)) {
                        throw new RuntimeException('Local backup is missing, unsafe, or failed its SHA-256 check.');
                    }

                    $key = $config['prefix'] . '/' . app()->environment() . '/' . basename($real);
                    $metadata = is_array($record->metadata) ? $record->metadata : [];
                    $offsite = is_array($metadata['offsite'] ?? null) ? $metadata['offsite'] : [];
                    $alreadyUploaded = ($offsite['sha256'] ?? null) === $record->checksum_sha256
                        && ($offsite['object_key'] ?? null) === $key;
                    if (!$alreadyUploaded) {
                        $this->request($config, 'PUT', $key, $real);
                        $metadata['offsite'] = [
                            'provider' => 's3-compatible',
                            'object_key' => $key,
                            'sha256' => $record->checksum_sha256,
                            'uploaded_at' => now()->toIso8601String(),
                        ];
                        $record->forceFill(['metadata' => $metadata])->save();
                        $result['uploaded']++;
                    }

                    if ($verifyDownloads) {
                        $download = tempnam(sys_get_temp_dir(), 'aec_offsite_verify_');
                        if ($download === false) {
                            throw new RuntimeException('Unable to allocate off-site verification file.');
                        }
                        try {
                            $this->request($config, 'GET', $key, null, $download);
                            $remoteHash = hash_file('sha256', $download);
                            if (!is_string($remoteHash) || !hash_equals((string) $record->checksum_sha256, $remoteHash)) {
                                throw new RuntimeException('Downloaded off-site backup checksum does not match local backup.');
                            }
                            $result['verified']++;
                        } finally {
                            @unlink($download);
                        }
                    }
                } catch (\Throwable $exception) {
                    $result['failed']++;
                    report($exception);
                }
            }
        });

        return $result;
    }

    /** @return array{endpoint:string,region:string,bucket:string,access:string,secret:string,prefix:string} */
    private function configuration(): array
    {
        $endpoint = rtrim((string) config('lms.backup_offsite.endpoint'), '/');
        $region = trim((string) config('lms.backup_offsite.region'));
        $bucket = trim((string) config('lms.backup_offsite.bucket'));
        $access = (string) config('lms.backup_offsite.access_key');
        $secret = (string) config('lms.backup_offsite.secret_key');
        $prefix = trim((string) config('lms.backup_offsite.prefix', 'aec-lms-backups'), '/');
        $endpointParts = parse_url($endpoint);

        if (!is_array($endpointParts) || ($endpointParts['scheme'] ?? null) !== 'https'
            || !is_string($endpointParts['host'] ?? null) || $endpointParts['host'] === ''
            || isset($endpointParts['user']) || isset($endpointParts['pass'])
            || $region === '' || $bucket === '' || $access === '' || $secret === '' || $prefix === ''
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,62}$/', $bucket) !== 1
            || str_contains($endpoint, '?') || str_contains($endpoint, '#')
            || preg_match('/(^|\\/)\\.\\.?($|\\/)/', $prefix) === 1) {
            throw new RuntimeException('Off-site backup is not configured. Set an HTTPS S3-compatible endpoint, region, bucket, access key, and secret key in the private environment.');
        }

        return compact('endpoint', 'region', 'bucket', 'access', 'secret', 'prefix');
    }

    private function request(array $config, string $method, string $key, ?string $uploadPath = null, ?string $downloadPath = null): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for off-site backup transport.');
        }

        $endpoint = parse_url($config['endpoint']);
        $host = (string) $endpoint['host'] . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '');
        $basePath = rtrim((string) ($endpoint['path'] ?? ''), '/');
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $uri = $basePath . '/' . rawurlencode($config['bucket']) . '/' . $encodedKey;
        $url = $config['endpoint'] . '/' . rawurlencode($config['bucket']) . '/' . $encodedKey;
        $date = gmdate('Ymd\THis\Z');
        $day = substr($date, 0, 8);
        $payloadHash = $uploadPath !== null ? hash_file('sha256', $uploadPath) : hash('sha256', '');
        if (!is_string($payloadHash)) {
            throw new RuntimeException('Unable to hash backup before off-site transfer.');
        }

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
        ];
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $canonicalRequest = $method . "\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $scope = $day . '/' . $config['region'] . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $day, 'AWS4' . $config['secret'], true);
        $kRegion = hash_hmac('sha256', $config['region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $config['access'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $curl = curl_init($url);
        $requestHeaders = [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $date,
            'Authorization: ' . $authorization,
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => $downloadPath === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $input = null;
        $output = null;
        if ($uploadPath !== null) {
            $input = fopen($uploadPath, 'rb');
            if ($input === false) {
                curl_close($curl);
                throw new RuntimeException('Unable to open local backup for off-site upload.');
            }
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $input;
            $options[CURLOPT_INFILESIZE] = filesize($uploadPath);
        }
        if ($downloadPath !== null) {
            $output = fopen($downloadPath, 'wb');
            if ($output === false) {
                if (is_resource($input)) fclose($input);
                curl_close($curl);
                throw new RuntimeException('Unable to open off-site verification destination.');
            }
            $options[CURLOPT_FILE] = $output;
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        curl_close($curl);

        if ($response === false || $status < 200 || $status >= 300) {
            if ($downloadPath !== null) @unlink($downloadPath);
            throw new RuntimeException('Off-site object storage request failed (HTTP ' . $status . '): ' . ($error !== '' ? $error : 'provider rejected request'));
        }
    }
}
