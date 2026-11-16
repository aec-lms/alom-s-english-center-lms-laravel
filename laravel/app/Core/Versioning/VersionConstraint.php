<?php

declare(strict_types=1);

namespace App\Core\Versioning;

final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        $version = trim($version);
        $constraint = trim($constraint);

        if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $constraint, $matches) === 1) {
            $major = (int) $matches[1];
            $minor = (int) ($matches[2] ?? 0);
            $patch = (int) ($matches[3] ?? 0);
            $minimum = "{$major}.{$minor}.{$patch}";
            $maximum = ($major + 1) . '.0.0';

            return version_compare($version, $minimum, '>=')
                && version_compare($version, $maximum, '<');
        }

        if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $constraint, $matches) === 1) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $hasPatch = isset($matches[3]) && $matches[3] !== '';
            $patch = (int) ($matches[3] ?? 0);
            $minimum = "{$major}.{$minor}.{$patch}";
            $maximum = $hasPatch
                ? "{$major}." . ($minor + 1) . '.0'
                : ($major + 1) . '.0.0';

            return version_compare($version, $minimum, '>=')
                && version_compare($version, $maximum, '<');
        }

        if (preg_match('/^>=\s*(\d+)\.(\d+)(?:\.(\d+))?$/', $constraint, $matches) === 1) {
            $minimum = (int) $matches[1] . '.' . (int) $matches[2] . '.' . (int) ($matches[3] ?? 0);

            return version_compare($version, $minimum, '>=');
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $constraint) === 1) {
            return version_compare($version, $constraint, '==');
        }

        return false;
    }
}
