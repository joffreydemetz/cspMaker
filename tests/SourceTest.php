<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Source;
use JDZ\CspMaker\Exception\CspMakerException;

final class SourceTest extends TestCase
{
    public static function normalizeProvider(): array
    {
        return [
            'bare self' => ['self', "'self'"],
            'uppercase bare keyword' => ['UNSAFE-INLINE', "'unsafe-inline'"],
            'padded bare keyword' => ['  self  ', "'self'"],
            'quoted keyword kept' => ["'self'", "'self'"],
            // browsers match keywords case-insensitively: 'SELF' was kept as is, beside 'self'
            'quoted uppercase keyword lowercased' => ["'SELF'", "'self'"],
            'quoted mixed-case keyword lowercased' => ["'Unsafe-Inline'", "'unsafe-inline'"],
            'quoted nonce keeps its base64 case' => ["'nonce-AbC'", "'nonce-AbC'"],
            'bare none' => ['none', "'none'"],
            'bare unsafe-eval' => ['unsafe-eval', "'unsafe-eval'"],
            'bare strict-dynamic' => ['strict-dynamic', "'strict-dynamic'"],
            'bare unsafe-hashes' => ['unsafe-hashes', "'unsafe-hashes'"],
            'bare wasm-unsafe-eval' => ['wasm-unsafe-eval', "'wasm-unsafe-eval'"],
            'bare report-sample' => ['report-sample', "'report-sample'"],
            'bare inline-speculation-rules' => ['inline-speculation-rules', "'inline-speculation-rules'"],
            'bare data scheme' => ['data', 'data:'],
            'bare https scheme' => ['https', 'https:'],
            'bare http scheme' => ['http', 'http:'],
            'bare blob scheme' => ['blob', 'blob:'],
            'bare mediastream scheme' => ['mediastream', 'mediastream:'],
            'bare filesystem scheme' => ['filesystem', 'filesystem:'],
            'bare ws scheme' => ['ws', 'ws:'],
            'bare wss scheme' => ['wss', 'wss:'],
            'uppercase bare scheme' => ['HTTPS', 'https:'],
            'explicit scheme lowercased' => ['DATA:', 'data:'],
            'unlisted explicit scheme lowercased' => ['Ms-Appx:', 'ms-appx:'],
            'scheme-host kept as typed' => ['HTTPS://CDN.Example.com', 'HTTPS://CDN.Example.com'],
            'trailing slash kept' => ['https://cdn.example.com/', 'https://cdn.example.com/'],
            'host with port kept' => ['cdn.example.com:8443', 'cdn.example.com:8443'],
            'bare host kept' => ['cdn.example.com', 'cdn.example.com'],
            'wildcard host kept' => ['*.example.com', '*.example.com'],
            'star kept' => ['*', '*'],
            'host starting with a keyword kept' => ['self.example.com', 'self.example.com'],
            'unlisted bare word is a host' => ['localhost', 'localhost'],
            'bare nonce quoted' => ['nonce-r4nd0m', "'nonce-r4nd0m'"],
            'quoted nonce kept' => ["'nonce-r4nd0m'", "'nonce-r4nd0m'"],
            'bare sha256 quoted, base64 case kept' => ['sha256-AbC+/9=', "'sha256-AbC+/9='"],
            'bare sha384 quoted' => ['sha384-XyZ=', "'sha384-XyZ='"],
            'bare sha512 quoted' => ['sha512-XyZ=', "'sha512-XyZ='"],
            'quoted hash kept' => ["'sha256-AbC='", "'sha256-AbC='"],
            'empty' => ['', ''],
            'blank' => ['   ', ''],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $source, string $expected): void
    {
        $this->assertSame($expected, Source::normalize($source));
    }

    public static function unsafeTokenProvider(): array
    {
        return [
            'semicolon ends the directive' => ['cdn.example.com;script-src', 'cdn.example.com;script-src'],
            'comma starts a second policy' => ["'self',script-src", "'self',script-src"],
            'semicolon inside a quoted keyword' => ["'self;'", "'self;'"],
            'trailing semicolon' => ['cdn.example.com;', 'cdn.example.com;'],
            'interior newline, escaped in the message' => ["cdn.example.com\nscript-src", 'cdn.example.com\nscript-src'],
            'interior tab' => ["a.example.com\tb.example.com", 'a.example.com\tb.example.com'],
            'interior NUL byte' => ["cdn.example.com\0evil", 'cdn.example.com\000evil'],
            'trailing DEL' => ["cdn.example.com\x7F", 'cdn.example.com\177'],
        ];
    }

    #[DataProvider('unsafeTokenProvider')]
    public function testNormalizeRejectsUnsafeCharacters(string $source, string $reported): void
    {
        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage(sprintf('Invalid CSP token "%s": ";", "," and control characters are not allowed.', $reported));
        Source::normalize($source);
    }

    public static function rankProvider(): array
    {
        return [
            'none first' => ["'none'", 0],
            'self second' => ["'self'", 1],
            'unsafe-inline keyword' => ["'unsafe-inline'", 2],
            'strict-dynamic keyword' => ["'strict-dynamic'", 2],
            'data scheme' => ['data:', 3],
            'https scheme' => ['https:', 3],
            'scheme-host' => ['https://cdn.example.com', 4],
            'host with port' => ['cdn.example.com:8443', 4],
            'star' => ['*', 4],
            'nonce last' => ["'nonce-r4nd0m'", 5],
            'sha256 last' => ["'sha256-AbC='", 5],
            'sha512 last' => ["'sha512-AbC='", 5],
            // the prefix check was case-sensitive: 'SHA256-…' ranked as a keyword
            'uppercase hash prefix last' => ["'SHA256-AbC='", 5],
        ];
    }

    #[DataProvider('rankProvider')]
    public function testRank(string $normalized, int $expected): void
    {
        $this->assertSame($expected, Source::rank($normalized));
    }

    public static function isNoneProvider(): array
    {
        return [
            'quoted none' => ["'none'", true],
            'bare none is not normalized' => ['none', false],
            'self' => ["'self'", false],
        ];
    }

    #[DataProvider('isNoneProvider')]
    public function testIsNone(string $normalized, bool $expected): void
    {
        $this->assertSame($expected, Source::isNone($normalized));
    }

    public static function renderedSourcesProvider(): array
    {
        return [
            'every rank given in reverse' => [
                ["'sha256-B='", 'nonce-A', 'https://b.example.com', 'a.example.com', 'https', 'data', 'unsafe-inline', 'strict-dynamic', 'self', 'none'],
                "script-src 'self' 'strict-dynamic' 'unsafe-inline' data: https: a.example.com https://b.example.com 'nonce-A' 'sha256-B=';",
            ],
            'self in every spelling' => [
                ['self', "'self'", 'SELF', ' self '],
                "script-src 'self';",
            ],
            'nonce bare and quoted' => [
                ['nonce-abc', "'nonce-abc'"],
                "script-src 'nonce-abc';",
            ],
            'scheme bare and explicit' => [
                ['data', 'data:', 'DATA:'],
                'script-src data:;',
            ],
            'none dropped beside a nonce' => [
                ['none', 'nonce-abc'],
                "script-src 'nonce-abc';",
            ],
            'space-joined string split and normalized' => [
                ["'self' https://a.example.com nonce-abc sha256-AbC="],
                "script-src 'self' https://a.example.com 'nonce-abc' 'sha256-AbC=';",
            ],
        ];
    }

    #[DataProvider('renderedSourcesProvider')]
    public function testSourcesRenderRankedAndDeduplicated(array $sources, string $expected): void
    {
        $this->assertSame($expected, (new Policy())->add('script-src', $sources)->build());
    }
}
