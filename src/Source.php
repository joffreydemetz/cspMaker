<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker;

/**
 * Normalizes and classifies a single CSP source expression.
 *
 * Accepts the terse forms people actually type (`self`, `data`, `https`) and
 * turns them into valid CSP tokens (`'self'`, `data:`, `https:`), while leaving
 * hosts, already-quoted keywords, nonces and hashes untouched. Classification
 * drives a stable render order (keywords, then schemes, then hosts, then
 * nonces/hashes).
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Source
{
    /** Keyword sources that must be wrapped in single quotes. */
    private const KEYWORDS = [
        'none',
        'self',
        'unsafe-inline',
        'unsafe-eval',
        'strict-dynamic',
        'unsafe-hashes',
        'wasm-unsafe-eval',
        'report-sample',
        'inline-speculation-rules',
    ];

    /** Bare words that denote a scheme and take a trailing colon. */
    private const SCHEMES = [
        'data',
        'https',
        'http',
        'blob',
        'mediastream',
        'filesystem',
        'ws',
        'wss',
    ];

    private const HASH_PREFIXES = ['nonce-', 'sha256-', 'sha384-', 'sha512-'];

    /**
     * Normalize a raw source into a valid CSP token.
     */
    public static function normalize(string $source): string
    {
        $source = trim($source);

        if ('' === $source) {
            return '';
        }

        // Already a quoted keyword / nonce / hash.
        if (str_starts_with($source, "'") && str_ends_with($source, "'")) {
            return $source;
        }

        // Already an explicit scheme (`data:`, `https:`) or scheme-host (`https://x`).
        if (str_contains($source, ':')) {
            $lower = strtolower($source);
            // bare "data:" style — lowercase the scheme part
            if (preg_match('/^[a-z][a-z0-9+.\-]*:$/i', $source)) {
                return $lower;
            }
            return $source;
        }

        $lower = strtolower($source);

        if (in_array($lower, self::KEYWORDS, true)) {
            return "'" . $lower . "'";
        }

        if (in_array($lower, self::SCHEMES, true)) {
            return $lower . ':';
        }

        foreach (self::HASH_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return "'" . $source . "'";
            }
        }

        // A host / host-pattern (example.com, *.example.com, cdn.example.com:8443).
        return $source;
    }

    public static function isNone(string $normalized): bool
    {
        return "'none'" === $normalized;
    }

    /** Ordering bucket for a normalized token — lower renders first. */
    public static function rank(string $normalized): int
    {
        return match (true) {
            "'none'" === $normalized => 0,
            "'self'" === $normalized => 1,
            self::isKeyword($normalized) => 2,
            self::isScheme($normalized) => 3,
            self::isHashOrNonce($normalized) => 5,
            default => 4, // hosts
        };
    }

    private static function isKeyword(string $normalized): bool
    {
        return str_starts_with($normalized, "'")
            && !self::isHashOrNonce($normalized);
    }

    private static function isScheme(string $normalized): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9+.\-]*:$/', $normalized);
    }

    private static function isHashOrNonce(string $normalized): bool
    {
        $inner = trim($normalized, "'");
        foreach (self::HASH_PREFIXES as $prefix) {
            if (str_starts_with($inner, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
