<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker;

use JDZ\CspMaker\Exception\CspMakerException;

/**
 * The Content Security Policy directive vocabulary.
 *
 * Single source of truth for which directives exist, their short aliases, and
 * how they fall back. Adding a source to an unknown directive throws — that is
 * what makes a misspelled or misfiled category impossible instead of silent.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Directives
{
    /**
     * Fetch directives — take a source list and fall back to `default-src`
     * when not explicitly set.
     */
    public const FETCH = [
        'default-src',
        'child-src',
        'connect-src',
        'font-src',
        'frame-src',
        'img-src',
        'manifest-src',
        'media-src',
        'object-src',
        'prefetch-src',
        'script-src',
        'script-src-elem',
        'script-src-attr',
        'style-src',
        'style-src-elem',
        'style-src-attr',
        'worker-src',
    ];

    /** Document / navigation / reporting directives — a source list, no default-src fallback. */
    public const OTHER_SOURCE_LIST = [
        'base-uri',
        'form-action',
        'frame-ancestors',
    ];

    /** Directives that carry a bare value rather than a source list. */
    public const VALUE = [
        'report-uri',
        'report-to',
        'sandbox',
        'require-trusted-types-for',
        'trusted-types',
    ];

    /** Standalone directives — no value at all. */
    public const STANDALONE = [
        'upgrade-insecure-requests',
        'block-all-mixed-content',
    ];

    /**
     * Short / legacy names accepted on input, mapped to their canonical form.
     * Covers the terse `script`/`img`/… shape site configs have historically used,
     * plus a couple of historically-misspelled variants.
     */
    public const ALIASES = [
        'default' => 'default-src',
        'script' => 'script-src',
        'script-elem' => 'script-src-elem',
        'script-elem-src' => 'script-src-elem',
        'script-attr' => 'script-src-attr',
        'style' => 'style-src',
        'style-elem' => 'style-src-elem',
        'style-attr' => 'style-src-attr',
        'font' => 'font-src',
        'connect' => 'connect-src',
        'img' => 'img-src',
        'image' => 'img-src',
        'frame' => 'frame-src',
        'child' => 'child-src',
        'media' => 'media-src',
        'object' => 'object-src',
        'manifest' => 'manifest-src',
        'worker' => 'worker-src',
        'workers' => 'worker-src',
        'prefetch' => 'prefetch-src',
    ];

    /** Every canonical directive name, in a stable render order. */
    public static function all(): array
    {
        return [
            ...self::FETCH,
            ...self::OTHER_SOURCE_LIST,
            ...self::VALUE,
            ...self::STANDALONE,
        ];
    }

    /**
     * Resolve a user-supplied name to its canonical directive.
     *
     * @throws CspMakerException when the name is neither a known directive nor a known alias.
     */
    public static function canonical(string $name): string
    {
        $name = strtolower(trim($name));

        if (isset(self::ALIASES[$name])) {
            $name = self::ALIASES[$name];
        }

        if (!in_array($name, self::all(), true)) {
            throw new CspMakerException(sprintf(
                'Unknown CSP directive "%s". Known directives: %s.',
                $name,
                implode(', ', self::all())
            ));
        }

        return $name;
    }

    public static function isSourceList(string $canonical): bool
    {
        return in_array($canonical, self::FETCH, true)
            || in_array($canonical, self::OTHER_SOURCE_LIST, true);
    }

    public static function isFetch(string $canonical): bool
    {
        return in_array($canonical, self::FETCH, true);
    }

    public static function isStandalone(string $canonical): bool
    {
        return in_array($canonical, self::STANDALONE, true);
    }

    /** Position of a directive in the canonical render order (unknowns sort last). */
    public static function order(string $canonical): int
    {
        $pos = array_search($canonical, self::all(), true);
        return false === $pos ? PHP_INT_MAX : $pos;
    }
}
