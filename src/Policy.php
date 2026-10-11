<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker;

/**
 * An immutable-ish, normalized Content Security Policy.
 *
 * Holds directives keyed by their canonical name, each carrying a deduped,
 * normalized token list. Rendering applies a stable order and collapses a
 * `'none'` source list to just `'none'`. Every mutation validates the
 * directive name through {@see Directives}, so a misspelled category throws
 * rather than silently landing in a bucket nobody reads.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class Policy
{
    /** canonical directive => list of tokens (normalized for source lists, raw for value directives) */
    protected array $directives = [];

    /**
     * @param array<string,string|array|bool|null> $policy  directive => source(s)
     */
    public function __construct(array $policy = [])
    {
        $this->merge($policy);
    }

    public function __toString(): string
    {
        return $this->build();
    }

    /**
     * Add source(s) to a directive, keeping existing ones.
     *
     * @param string|array|bool|null $sources  null (none given) adds nothing, but turns a
     *                                         standalone directive on; false turns it off
     */
    public function add(string $directive, string|array|bool|null $sources = null): self
    {
        return $this->put($directive, $sources, false);
    }

    /**
     * Replace a directive's source(s) outright.
     *
     * @param string|array|bool|null $sources
     */
    public function set(string $directive, string|array|bool|null $sources = null): self
    {
        return $this->put($directive, $sources, true);
    }

    /**
     * Remove a whole directive, or just the given source(s) from it.
     *
     * @param string|array|null $sources  null removes the directive entirely
     */
    public function remove(string $directive, string|array|null $sources = null): self
    {
        $canonical = Directives::canonical($directive);

        if (null === $sources) {
            unset($this->directives[$canonical]);
            return $this;
        }

        if (!isset($this->directives[$canonical])) {
            return $this;
        }

        $drop = $this->tokenize($canonical, $sources);
        $this->directives[$canonical] = array_values(array_diff($this->directives[$canonical], $drop));

        if ([] === $this->directives[$canonical] && Directives::isSourceList($canonical)) {
            unset($this->directives[$canonical]);
        }

        return $this;
    }

    /**
     * Merge a directive => source(s) map.
     *
     * @param array<string,string|array|bool|null> $policy
     */
    public function merge(array $policy): self
    {
        foreach ($policy as $directive => $sources) {
            $this->add((string) $directive, $sources);
        }
        return $this;
    }

    public function has(string $directive): bool
    {
        return isset($this->directives[Directives::canonical($directive)]);
    }

    /** Normalized tokens for a directive after `'none'` collapse (empty if unset). */
    public function get(string $directive): array
    {
        $canonical = Directives::canonical($directive);
        return $this->collapse($this->directives[$canonical] ?? []);
    }

    /**
     * Is this directive effectively constrained — either set explicitly, or a
     * fetch directive backed by `default-src`?
     */
    public function covers(string $directive): bool
    {
        $canonical = Directives::canonical($directive);

        if (isset($this->directives[$canonical])) {
            return true;
        }

        return Directives::isFetch($canonical) && isset($this->directives['default-src']);
    }

    /** @return array<string,array> canonical directive => tokens, in render order */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->orderedDirectives() as $canonical) {
            $out[$canonical] = $this->collapse($this->directives[$canonical]);
        }
        return $out;
    }

    /** Build the CSP header value (no header name, no surrounding quotes). */
    public function build(): string
    {
        $parts = [];

        foreach ($this->orderedDirectives() as $canonical) {
            $tokens = $this->collapse($this->directives[$canonical]);

            // standalone, or a restrictive value directive in its strictest form
            if (Directives::isStandalone($canonical) || ([] === $tokens && Directives::allowsEmptyValue($canonical))) {
                $parts[] = $canonical . ';';
                continue;
            }

            // Any other source-list / value directive with nothing in it is meaningless.
            if ([] === $tokens) {
                continue;
            }

            $parts[] = $canonical . ' ' . implode(' ', $tokens) . ';';
        }

        return implode(' ', $parts);
    }

    /**
     * Non-fatal advisories about the policy.
     *
     * @return string[]
     */
    public function lint(): array
    {
        $warnings = [];

        if (!isset($this->directives['default-src'])) {
            $warnings[] = 'No default-src set — fetch directives you did not list are unrestricted.';
        }

        foreach (['script-src', 'style-src'] as $d) {
            if (isset($this->directives[$d]) && in_array("'unsafe-inline'", $this->directives[$d], true)) {
                $warnings[] = sprintf("%s allows 'unsafe-inline' — weakens XSS protection.", $d);
            }
        }

        if (!$this->covers('object-src')) {
            $warnings[] = "object-src is not constrained — consider object-src 'none'.";
        }

        foreach ($this->directives as $canonical => $tokens) {
            if (Directives::isSourceList($canonical) && [] === $this->collapse($tokens)) {
                $warnings[] = sprintf('%s is present but empty — it will be dropped from output.', $canonical);
            }
        }

        return $warnings;
    }

    private function put(string $directive, string|array|bool|null $sources, bool $overwrite): self
    {
        $canonical = Directives::canonical($directive);

        if (Directives::isStandalone($canonical)) {
            // presence is the whole meaning; null (none given) turns it on, a falsey value removes it
            if (false === $sources || [] === $sources || '' === $sources) {
                unset($this->directives[$canonical]);
            } else {
                $this->directives[$canonical] = [];
            }
            return $this;
        }

        // `sandbox` / `trusted-types` with no value are their strictest form, so
        // `false` must switch them off rather than leave them bare
        if (false === $sources && Directives::allowsEmptyValue($canonical)) {
            unset($this->directives[$canonical]);
            return $this;
        }

        $tokens = $this->tokenize($canonical, $sources);

        if ($overwrite || !isset($this->directives[$canonical])) {
            // a fetch directive created on the fly stops the default-src fallback for its
            // resources: it starts from default-src's sources (it started empty, so an
            // integration or allowInline() blocked the site's own scripts)
            $seed = !$overwrite && 'default-src' !== $canonical && Directives::isFetch($canonical);
            $this->directives[$canonical] = $seed ? ($this->directives['default-src'] ?? []) : [];
        }

        foreach ($tokens as $token) {
            if ('' !== $token && !in_array($token, $this->directives[$canonical], true)) {
                $this->directives[$canonical][] = $token;
            }
        }

        return $this;
    }

    /**
     * Split + normalize sources for a directive. Source-list directives get the
     * full token normalization; value directives (report-uri, sandbox, …) keep
     * their raw tokens.
     *
     * @param string|array|bool|null $sources
     * @return string[]
     */
    private function tokenize(string $canonical, string|array|bool|null $sources): array
    {
        if (null === $sources || is_bool($sources)) {
            return [];
        }

        $raw = [];
        foreach ((array) $sources as $chunk) {
            foreach (preg_split('/\s+/', trim((string) $chunk)) ?: [] as $piece) {
                if ('' !== $piece) {
                    // value directives skip normalize(), so guard every token here
                    Source::assertSafe($piece);
                    $raw[] = $piece;
                }
            }
        }

        if (!Directives::isSourceList($canonical)) {
            return array_values(array_unique($raw));
        }

        $tokens = [];
        foreach ($raw as $piece) {
            $tokens[] = Source::normalize($piece);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Normalize `'none'`: it is only meaningful as the sole source. Alongside
     * real sources it is a spec no-op, so drop it — never let a bare `'none'`
     * silently swallow a host someone added.
     */
    private function collapse(array $tokens): array
    {
        $others = array_values(array_filter($tokens, fn(string $t): bool => "'none'" !== $t));

        if ([] === $others) {
            return in_array("'none'", $tokens, true) ? ["'none'"] : [];
        }

        $tokens = $others;

        usort($tokens, function (string $a, string $b): int {
            $ra = Source::rank($a);
            $rb = Source::rank($b);
            return $ra === $rb ? strcmp($a, $b) : $ra <=> $rb;
        });

        return $tokens;
    }

    /** @return string[] canonical directive names present, in render order */
    private function orderedDirectives(): array
    {
        $names = array_keys($this->directives);
        usort($names, fn(string $a, string $b): int => Directives::order($a) <=> Directives::order($b));
        return $names;
    }
}
