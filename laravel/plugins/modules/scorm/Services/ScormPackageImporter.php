<?php

declare(strict_types=1);

namespace Plugins\Modules\Scorm\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class ScormPackageImporter
{
    private const MAX_ARCHIVE_BYTES = 209715200;
    private const MAX_EXPANDED_BYTES = 524288000;
    private const MAX_FILE_BYTES = 209715200;
    private const MAX_ENTRIES = 5000;
    private const MAX_COMPRESSION_RATIO = 200;

    /**
     * Validate and privately extract one SCORM 1.2 package.
     *
     * @return array{directory:string,title:string,launch_file:string,manifest:string,package_sha256:string,file_count:int,total_bytes:int}
     */
    public function import(string $archivePath): array
    {
        if (!is_file($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('SCORM archive is missing or is not a regular file.');
        }

        $archiveSize = filesize($archivePath);
        if ($archiveSize === false || $archiveSize < 1 || $archiveSize > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('SCORM archive exceeds the configured upload size limit.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('SCORM archive is not a readable ZIP file.');
        }

        $directory = null;
        try {
            $entries = $this->inspectArchive($zip);
            $manifestName = $this->findManifest($entries);
            $manifestBytes = $zip->getFromName($manifestName);
            if (!is_string($manifestBytes) || strlen($manifestBytes) > 1048576) {
                throw new RuntimeException('SCORM manifest is missing or exceeds 1 MiB.');
            }

            $manifest = $this->parseManifest($manifestBytes, $entries);
            $directory = $this->createPrivatePackageDirectory();
            $this->extractEntries($zip, $entries, $directory);

            $launchPath = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifest['launch_file']);
            $resolvedLaunch = realpath($launchPath);
            $resolvedRoot = realpath($directory);
            if ($resolvedLaunch === false || $resolvedRoot === false
                || !is_file($resolvedLaunch) || is_link($launchPath)
                || !str_starts_with($resolvedLaunch, $resolvedRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('SCORM launch resource is missing or escaped the private package directory.');
            }

            return [
                'directory' => $directory,
                'title' => $manifest['title'],
                'launch_file' => $manifest['launch_file'],
                'manifest' => $manifestName,
                'package_sha256' => $this->directoryChecksum($directory),
                'file_count' => count($entries),
                'total_bytes' => array_sum(array_column($entries, 'size')),
            ];
        } catch (\Throwable $exception) {
            if ($directory !== null && is_dir($directory) && !is_link($directory)) {
                File::deleteDirectory($directory);
            }
            throw $exception;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, array{name:string,size:int,compressed:int,is_dir:bool}>
     */
    private function inspectArchive(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('SCORM archive contains an invalid number of entries.');
        }

        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                throw new RuntimeException('SCORM archive contains an unreadable ZIP entry.');
            }

            $name = $this->normalizeEntryName((string) $stat['name']);
            $key = strtolower($name);
            if (isset($entries[$key])) {
                throw new RuntimeException('SCORM archive contains duplicate or case-colliding paths.');
            }

            if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new RuntimeException('Encrypted SCORM ZIP entries are not supported.');
            }

            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];
            if ($size < 0 || $size > self::MAX_FILE_BYTES) {
                throw new RuntimeException('SCORM archive contains an oversized file.');
            }
            if ($size > 0 && ($compressed < 1 || $size / $compressed > self::MAX_COMPRESSION_RATIO)) {
                throw new RuntimeException('SCORM archive contains a suspicious compression ratio.');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attributes >> 16) & 0xFFFF;
                    $type = $mode & 0170000;
                    if ($type !== 0 && !in_array($type, [0100000, 0040000], true)) {
                        throw new RuntimeException('SCORM archive contains a symbolic link or special filesystem entry.');
                    }
                    if (($type === 0040000) !== str_ends_with($name, '/')) {
                        throw new RuntimeException('SCORM entry type does not match its path.');
                    }
                }
            }

            $isDirectory = str_ends_with($name, '/');
            if (!$isDirectory && $size === 0 && $compressed > 0) {
                // Empty files are valid; their compressed size may be non-zero.
            }
            $total += $size;
            if ($total > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('SCORM archive expands beyond the configured total size limit.');
            }

            $entries[$key] = [
                'name' => $name,
                'size' => $size,
                'compressed' => $compressed,
                'is_dir' => $isDirectory,
            ];
        }

        return $entries;
    }

    private function normalizeEntryName(string $name): string
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || str_contains($name, '\\') || str_contains($name, '%') || str_contains($name, ':')) {
            throw new RuntimeException('SCORM archive contains an invalid path.');
        }
        if (str_starts_with($name, './') || str_starts_with($name, '/')
            || preg_match('/^[a-zA-Z]:/', $name) === 1) {
            throw new RuntimeException('SCORM archive contains an absolute or ambiguous path.');
        }

        $directory = str_ends_with($name, '/');
        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                throw new RuntimeException('SCORM archive contains a traversal or ambiguous path segment.');
            }
        }

        return implode('/', $segments) . ($directory ? '/' : '');
    }

    /**
     * @param array<string, array{name:string,size:int,compressed:int,is_dir:bool}> $entries
     */
    private function findManifest(array $entries): string
    {
        foreach ($entries as $entry) {
            if (strcasecmp($entry['name'], 'imsmanifest.xml') === 0) {
                return $entry['name'];
            }
        }

        throw new RuntimeException('SCORM package must contain imsmanifest.xml at its archive root.');
    }

    /**
     * @param array<string, array{name:string,size:int,compressed:int,is_dir:bool}> $entries
     * @return array{title:string,launch_file:string}
     */
    private function parseManifest(string $xml, array $entries): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('SCORM manifest document types and entity declarations are not allowed.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException('SCORM manifest is not valid XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'manifest'
            || $root->namespaceURI !== 'http://www.imsproject.org/xsd/imscp_rootv1p1p2') {
            throw new RuntimeException('SCORM manifest root element is invalid.');
        }

        $title = trim((string) ($document->getElementsByTagName('title')->item(0)?->textContent ?? 'SCORM Course'));
        $resourceById = [];
        foreach ($document->getElementsByTagName('resource') as $resource) {
            if (!$resource instanceof DOMElement) {
                continue;
            }
            $id = trim($resource->getAttribute('identifier'));
            $href = trim($resource->getAttribute('href'));
            $resourceType = trim($resource->getAttribute('type'));
            $scormType = trim($resource->getAttributeNS('http://www.adlnet.org/xsd/adlcp_rootv1p2', 'scormtype'));
            if ($id !== '' && $href !== '' && $resourceType === 'webcontent' && $scormType === 'sco') {
                $resourceById[$id] = $this->normalizeLaunchHref($href);
            }
        }

        $launch = null;
        foreach ($document->getElementsByTagName('item') as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }
            $reference = trim($item->getAttribute('identifierref'));
            if ($reference !== '' && isset($resourceById[$reference])) {
                $launch = $resourceById[$reference];
                break;
            }
        }
        if ($launch === null) {
            throw new RuntimeException('SCORM manifest does not reference a launchable resource.');
        }

        $entryNames = array_fill_keys(array_map(static fn (array $entry): string => $entry['name'], $entries), true);
        if (!isset($entryNames[$launch])) {
            throw new RuntimeException('SCORM launch resource does not exist in the archive.');
        }

        return ['title' => $title !== '' ? mb_substr($title, 0, 255) : 'SCORM Course', 'launch_file' => $launch];
    }

    private function normalizeLaunchHref(string $href): string
    {
        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1
            || str_contains($href, '\\') || str_contains($href, '%') || str_contains($href, ':')
            || str_starts_with($href, '/') || str_contains($href, '?') || str_contains($href, '#')) {
            throw new RuntimeException('SCORM manifest contains an unsafe launch URL.');
        }

        $segments = explode('/', $href);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                throw new RuntimeException('SCORM manifest launch path is ambiguous or escapes the package.');
            }
        }

        return implode('/', $segments);
    }

    private function createPrivatePackageDirectory(): string
    {
        $storagePath = storage_path('app');
        $storageRoot = realpath($storagePath);
        if ($storageRoot === false || is_link($storagePath)) {
            throw new RuntimeException('SCORM private storage root is unavailable or redirected.');
        }

        $root = $storageRoot . DIRECTORY_SEPARATOR . 'scorm-packages';
        if (is_link($root)) {
            throw new RuntimeException('SCORM package directory cannot be a symbolic link.');
        }
        File::ensureDirectoryExists($root);
        if (!@chmod($root, 0700)) {
            throw new RuntimeException('SCORM package directory permissions could not be restricted.');
        }
        $resolvedRoot = realpath($root);
        $rootStat = $resolvedRoot !== false ? @stat($resolvedRoot) : false;
        if ($resolvedRoot === false || dirname($resolvedRoot) !== $storageRoot || $rootStat === false
            || (($rootStat['mode'] & 0170000) !== 0040000) || (($rootStat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('SCORM package directory must be a private real directory under storage/app.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = $resolvedRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
            if (@mkdir($directory, 0700)) {
                return $directory;
            }
        }

        throw new RuntimeException('Unable to allocate a private SCORM package directory.');
    }

    /**
     * @param array<string, array{name:string,size:int,compressed:int,is_dir:bool}> $entries
     */
    private function extractEntries(ZipArchive $zip, array $entries, string $root): void
    {
        $root = realpath($root) ?: throw new RuntimeException('SCORM package extraction directory disappeared.');
        $previousUmask = umask(0077);
        try {
            foreach ($entries as $entry) {
                $relative = rtrim($entry['name'], '/');
                $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = dirname($target);
                if (!$this->ensurePrivateDirectoryTree($parent, $root)) {
                    throw new RuntimeException('Unable to create a private SCORM package directory tree.');
                }

                if ($entry['is_dir']) {
                    if (!is_dir($target) && !@mkdir($target, 0700)) {
                        throw new RuntimeException('Unable to create a SCORM package directory.');
                    }
                    continue;
                }

                $input = $zip->getStream($entry['name']);
                if (!is_resource($input)) {
                    throw new RuntimeException('Unable to stream a SCORM package entry.');
                }
                $output = @fopen($target, 'x+b');
                if (!is_resource($output)) {
                    fclose($input);
                    throw new RuntimeException('SCORM package entry destination already exists or is unsafe.');
                }

                try {
                    $copied = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                    if ($copied === false || $copied !== $entry['size'] || !fflush($output)) {
                        throw new RuntimeException('SCORM package entry was truncated or could not be flushed.');
                    }
                    if (function_exists('fsync') && !@fsync($output)) {
                        throw new RuntimeException('SCORM package entry could not be synchronized to storage.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }

                if (!@chmod($target, 0600) || is_link($target) || !is_file($target)) {
                    throw new RuntimeException('SCORM package entry permissions or file type are unsafe.');
                }
            }
        } finally {
            umask($previousUmask);
        }
    }

    private function ensurePrivateDirectoryTree(string $directory, string $root): bool
    {
        if ($directory === $root) {
            return true;
        }
        if (file_exists($directory) || is_link($directory)) {
            $resolved = realpath($directory);
            return $resolved !== false && is_dir($resolved) && !is_link($directory)
                && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
        }

        $parent = dirname($directory);
        if (!$this->ensurePrivateDirectoryTree($parent, $root)) {
            return false;
        }

        return @mkdir($directory, 0700);
    }

    private function directoryChecksum(string $directory): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new RuntimeException('SCORM package contains an unexpected filesystem entry after extraction.');
            }
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $checksum = hash_file('sha256', $file->getPathname());
            if (!is_string($checksum)) {
                throw new RuntimeException('Unable to checksum an extracted SCORM package file.');
            }
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $checksum;
        }
        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
