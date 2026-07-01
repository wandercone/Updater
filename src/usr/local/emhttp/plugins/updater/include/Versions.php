<?php

declare(strict_types=1);

namespace UnraidUpdater;


final class Versions
{
    /**
     * Parse major.minor.patch, optionally stripping a leading 'v' or suffix.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function parse(string $version): ?array
    {
        $clean = trim($version);
        if ($clean === '') {
            return null;
        }
        if (!preg_match('/^(?:v)?(\d+)\.(\d+)\.(\d+)/i', $clean, $m)) {
            return null;
        }
        return [(int)$m[1], (int)$m[2], (int)$m[3]];
    }

    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);

        if ($pa === null || $pb === null) {
            return strcmp($a, $b);
        }

        for ($i = 0; $i < 3; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }

        // Same triplet: treat stable as newer than pre-release (7.3.0 > 7.3.0-rc1).
        $sa = self::suffix($a);
        $sb = self::suffix($b);
        if ($sa === '' && $sb !== '') {
            return 1;
        }
        if ($sa !== '' && $sb === '') {
            return -1;
        }
        return strcmp($sa, $sb);
    }

    public static function suffix(string $version): string
    {
        if (preg_match('/^(?:v)?\d+\.\d+\.\d+(.*)$/i', trim($version), $m)) {
            return strtolower(ltrim($m[1], '-_'));
        }
        return '';
    }

    public static function isNewer(string $candidate, string $installed): bool
    {
        return self::compare($candidate, $installed) > 0;
    }

    public static function sameMinorBranch(string $candidate, string $installed): bool
    {
        $pc = self::parse($candidate);
        $pi = self::parse($installed);
        return $pc !== null && $pi !== null && $pc[0] === $pi[0] && $pc[1] === $pi[1];
    }

    public static function isPrerelease(string $version): bool
    {
        return (bool)preg_match('/\b(rc|beta|alpha|preview|dev)\b/i', self::suffix($version));
    }

    /**
     * Newest public release matching the current major.minor branch.
     *
     * @param array<mixed> $releases
     * @return array<mixed>|null
     */
    public static function findNewestMatchingPatch(array $releases, string $installed, bool $includePrerelease): ?array
    {
        /** @var array<mixed>|null $best */
        $best = null;

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = self::stringFrom($release['version'] ?? '');
            if ($version === '') {
                continue;
            }

            if (($release['public'] ?? true) === false || ($release['published'] ?? true) === false) {
                continue;
            }

            if (!self::sameMinorBranch($version, $installed)) {
                continue;
            }

            if (!$includePrerelease && self::isPrerelease($version)) {
                continue;
            }

            if (!self::isNewer($version, $installed)) {
                continue;
            }

            if ($best === null || self::isNewer($version, self::stringFrom($best['version'] ?? ''))) {
                $best = $release;
            }
        }

        return $best;
    }

    private static function stringFrom(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
