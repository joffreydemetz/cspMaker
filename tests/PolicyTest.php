<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Exception\CspMakerException;

final class PolicyTest extends TestCase
{
    public function testShortAliasesResolveToCanonicalDirectives(): void
    {
        $policy = new Policy(['script' => 'self', 'img' => ['self', 'data']]);

        $this->assertStringContainsString("script-src 'self';", (string) $policy);
        $this->assertStringContainsString("img-src 'self' data:;", (string) $policy);
    }

    public static function unknownDirectiveProvider(): array
    {
        return [
            'typo' => ['scriptt-src', 'scriptt-src'],
            'shouted and padded, reported lowercased and trimmed' => [' SCRIPTT ', 'scriptt'],
        ];
    }

    #[DataProvider('unknownDirectiveProvider')]
    public function testUnknownDirectiveThrows(string $directive, string $reported): void
    {
        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage(sprintf(
            'Unknown CSP directive "%s". Known directives: default-src, child-src, connect-src, font-src, frame-src, img-src, manifest-src, media-src, object-src, prefetch-src, script-src, script-src-elem, script-src-attr, style-src, style-src-elem, style-src-attr, worker-src, base-uri, form-action, frame-ancestors, report-uri, report-to, sandbox, require-trusted-types-for, trusted-types, upgrade-insecure-requests, block-all-mixed-content.',
            $reported
        ));
        (new Policy())->add($directive, 'self');
    }

    public function testSourceKeywordsAndSchemesAreNormalized(): void
    {
        $policy = (new Policy())->add('font-src', ['self', 'data', 'https']);

        $this->assertSame("font-src 'self' data: https:;", $policy->build());
    }

    public function testDuplicateSourcesAreDeduped(): void
    {
        $policy = (new Policy())
            ->add('script-src', 'self')
            ->add('script-src', "'self'")
            ->add('script-src', 'self');

        $this->assertSame("script-src 'self';", $policy->build());
    }

    public function testSpaceJoinedStringIsSplitIntoTokens(): void
    {
        $policy = (new Policy())->add('script-src', "'self' 'unsafe-inline'");

        $this->assertSame("script-src 'self' 'unsafe-inline';", $policy->build());
    }

    public function testNoneAloneIsKept(): void
    {
        $policy = (new Policy())->add('default-src', 'none');

        $this->assertSame("default-src 'none';", $policy->build());
    }

    public function testNoneIsDroppedWhenRealSourcesPresent(): void
    {
        // 'none' among real sources is a spec no-op — it must not swallow the host
        $policy = (new Policy())->add('default-src', ['self', 'none', 'https://cdn.example.com']);

        $this->assertSame("default-src 'self' https://cdn.example.com;", $policy->build());
    }

    public function testEmptySourceListDirectiveIsDropped(): void
    {
        $policy = (new Policy())->add('frame-src', []);

        $this->assertSame('', $policy->build());
        $this->assertFalse($policy->has('frame-src') && $policy->get('frame-src') !== []);
    }

    public function testStandaloneDirectiveRendersWithoutValue(): void
    {
        $policy = (new Policy())->add('upgrade-insecure-requests', true);

        $this->assertSame('upgrade-insecure-requests;', $policy->build());
    }

    public static function noSourcesProvider(): array
    {
        return [
            'add with no sources turns a standalone directive on' => [
                static fn (Policy $p) => $p->add('upgrade-insecure-requests'),
                'upgrade-insecure-requests;',
            ],
            'set with no sources turns a standalone directive on' => [
                static fn (Policy $p) => $p->set('block-all-mixed-content'),
                'block-all-mixed-content;',
            ],
            'null turns a standalone directive on' => [
                static fn (Policy $p) => $p->add('upgrade-insecure-requests', null),
                'upgrade-insecure-requests;',
            ],
            'null in a merged map turns a standalone directive on' => [
                static fn (Policy $p) => $p->merge(['upgrade-insecure-requests' => null]),
                'upgrade-insecure-requests;',
            ],
            'null on a sandbox is its strictest form' => [
                static fn (Policy $p) => $p->merge(['sandbox' => null]),
                'sandbox;',
            ],
            'null on a source list adds nothing' => [
                static fn (Policy $p) => $p->add('script-src', 'self')->merge(['script-src' => null]),
                "script-src 'self';",
            ],
            'add with no sources on a source list adds nothing' => [
                static fn (Policy $p) => $p->add('default-src', 'self')->add('script-src'),
                "default-src 'self';",
            ],
        ];
    }

    #[DataProvider('noSourcesProvider')]
    public function testNoSources(\Closure $steps, string $expected): void
    {
        $this->assertSame($expected, $steps(new Policy())->build());
    }

    public static function emptyValueDirectiveProvider(): array
    {
        return [
            'sandbox true is its strictest form' => [
                static fn (Policy $p) => $p->add('sandbox', true),
                'sandbox;',
            ],
            'trusted-types empty string is its strictest form' => [
                static fn (Policy $p) => $p->add('trusted-types', ''),
                'trusted-types;',
            ],
            'sandbox empty list is its strictest form' => [
                static fn (Policy $p) => $p->add('sandbox', []),
                'sandbox;',
            ],
            'tokens added to a bare sandbox are kept' => [
                static fn (Policy $p) => $p->add('sandbox', true)->add('sandbox', 'allow-scripts'),
                'sandbox allow-scripts;',
            ],
            'true added to a sandbox keeps its tokens' => [
                static fn (Policy $p) => $p->add('sandbox', 'allow-scripts')->add('sandbox', true),
                'sandbox allow-scripts;',
            ],
            'set true resets a sandbox to its strictest form' => [
                static fn (Policy $p) => $p->add('sandbox', 'allow-scripts')->set('sandbox', true),
                'sandbox;',
            ],
            'removing the last sandbox token leaves its strictest form' => [
                static fn (Policy $p) => $p->add('sandbox', 'allow-scripts')->remove('sandbox', 'allow-scripts'),
                'sandbox;',
            ],
            'sandbox false is off' => [
                static fn (Policy $p) => $p->add('sandbox', false),
                '',
            ],
            'false removes an existing trusted-types, like a standalone directive' => [
                static fn (Policy $p) => $p->add('trusted-types', 'default')->add('trusted-types', false),
                '',
            ],
            'an empty report-uri is still dropped' => [
                static fn (Policy $p) => $p->add('report-uri', true),
                '',
            ],
        ];
    }

    #[DataProvider('emptyValueDirectiveProvider')]
    public function testEmptyValueDirective(\Closure $steps, string $expected): void
    {
        $this->assertSame($expected, $steps(new Policy())->build());
    }

    public function testValueDirectiveKeepsRawValue(): void
    {
        $policy = (new Policy())->add('report-uri', '/csp-report-endpoint');

        $this->assertStringContainsString('report-uri /csp-report-endpoint;', $policy->build());
    }

    public function testDirectivesRenderInCanonicalOrder(): void
    {
        $policy = (new Policy())
            ->add('img-src', 'self')
            ->add('default-src', 'self')
            ->add('script-src', 'self');

        // default-src leads; the rest follow the canonical (alphabetical) order,
        // regardless of insertion order
        $this->assertSame("default-src 'self'; img-src 'self'; script-src 'self';", $policy->build());
    }

    public function testRemoveSourceAndWholeDirective(): void
    {
        $policy = (new Policy())->add('script-src', ['self', 'https://cdn.example.com']);
        $policy->remove('script-src', 'https://cdn.example.com');
        $this->assertSame("script-src 'self';", $policy->build());

        $policy->remove('script-src');
        $this->assertSame('', $policy->build());
    }

    public function testCoversReflectsDefaultSrcFallback(): void
    {
        $policy = (new Policy())->add('default-src', 'self');

        $this->assertTrue($policy->covers('script-src'), 'fetch directive falls back to default-src');
        $this->assertFalse($policy->covers('frame-ancestors'), 'non-fetch directive has no fallback');
    }

    public function testEveryDirectiveRendersInCanonicalOrder(): void
    {
        // given in reverse canonical order
        $policy = new Policy([
            'block-all-mixed-content' => true,
            'upgrade-insecure-requests' => true,
            'trusted-types' => 'default',
            'require-trusted-types-for' => "'script'",
            'sandbox' => 'allow-scripts',
            'report-to' => 'csp-endpoint',
            'report-uri' => '/csp-report',
            'frame-ancestors' => 'self',
            'form-action' => 'self',
            'base-uri' => 'self',
            'worker-src' => 'self',
            'style-src-attr' => 'self',
            'style-src-elem' => 'self',
            'style-src' => 'self',
            'script-src-attr' => 'self',
            'script-src-elem' => 'self',
            'script-src' => 'self',
            'prefetch-src' => 'self',
            'object-src' => 'self',
            'media-src' => 'self',
            'manifest-src' => 'self',
            'img-src' => 'self',
            'frame-src' => 'self',
            'font-src' => 'self',
            'connect-src' => 'self',
            'child-src' => 'self',
            'default-src' => 'self',
        ]);

        $this->assertSame(
            "default-src 'self'; child-src 'self'; connect-src 'self'; font-src 'self'; frame-src 'self'; img-src 'self'; "
            . "manifest-src 'self'; media-src 'self'; object-src 'self'; prefetch-src 'self'; script-src 'self'; "
            . "script-src-elem 'self'; script-src-attr 'self'; style-src 'self'; style-src-elem 'self'; style-src-attr 'self'; "
            . "worker-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; report-uri /csp-report; "
            . "report-to csp-endpoint; sandbox allow-scripts; require-trusted-types-for 'script'; trusted-types default; "
            . 'upgrade-insecure-requests; block-all-mixed-content;',
            $policy->build()
        );
    }

    public function testToArrayIsCollapsedRankedAndInRenderOrder(): void
    {
        $policy = new Policy([
            'upgrade-insecure-requests' => true,
            'script-src' => ['https://cdn.example.com', 'nonce-abc', 'self', 'none'],
            'default-src' => 'none',
        ]);

        $this->assertSame([
            'default-src' => ["'none'"],
            'script-src' => ["'self'", 'https://cdn.example.com', "'nonce-abc'"],
            'upgrade-insecure-requests' => [],
        ], $policy->toArray());
    }

    public static function removeProvider(): array
    {
        return [
            'source given bare matches its normalized form' => [
                ['script-src' => ["'self'", 'https://cdn.example.com']], 'script', 'self',
                'script-src https://cdn.example.com;',
            ],
            'several sources at once' => [
                ['script-src' => ['self', 'unsafe-inline', 'nonce-abc']], 'script-src', "'unsafe-inline' 'nonce-abc'",
                "script-src 'self';",
            ],
            'last source removed drops the directive back to default-src' => [
                ['default-src' => 'self', 'script-src' => 'https://cdn.example.com'], 'script-src', 'https://cdn.example.com',
                "default-src 'self';",
            ],
            'source of an unset directive is a no-op' => [
                ['default-src' => 'self'], 'script-src', 'self',
                "default-src 'self';",
            ],
            'absent source is a no-op' => [
                ['script-src' => 'self'], 'script-src', 'https://cdn.example.com',
                "script-src 'self';",
            ],
            'whole standalone directive' => [
                ['default-src' => 'self', 'upgrade-insecure-requests' => true], 'upgrade-insecure-requests', null,
                "default-src 'self';",
            ],
        ];
    }

    #[DataProvider('removeProvider')]
    public function testRemove(array $initial, string $directive, string|array|null $sources, string $expected): void
    {
        $this->assertSame($expected, (new Policy($initial))->remove($directive, $sources)->build());
    }

    public static function lintProvider(): array
    {
        $noDefault = 'No default-src set — fetch directives you did not list are unrestricted.';
        $scriptInline = "script-src allows 'unsafe-inline' — weakens XSS protection.";
        $styleInline = "style-src allows 'unsafe-inline' — weakens XSS protection.";
        $objectSrc = "object-src is not constrained — consider object-src 'none'.";
        $frameEmpty = 'frame-src is present but empty — it will be dropped from output.';

        return [
            'locked-down policy is clean' => [
                ['default-src' => 'self', 'object-src' => 'none', 'base-uri' => 'self', 'frame-ancestors' => 'self'],
                [],
            ],
            'default-src none alone is clean' => [['default-src' => 'none'], []],
            'nonce- and hash-based sources are clean' => [
                ['default-src' => 'self', 'script-src' => ['self', 'nonce-abc'], 'style-src' => ['self', 'sha256-AbC=']],
                [],
            ],
            'empty policy' => [[], [$noDefault, $objectSrc]],
            'object-src none without default-src' => [['object-src' => 'none'], [$noDefault]],
            'script-src unsafe-inline (alias, bare keyword)' => [
                ['default-src' => 'self', 'script' => ['self', 'unsafe-inline']],
                [$scriptInline],
            ],
            'style-src unsafe-inline' => [
                ['default-src' => 'self', 'style-src' => "'self' 'unsafe-inline'"],
                [$styleInline],
            ],
            'source list present but empty' => [
                ['default-src' => 'self', 'frame-src' => []],
                [$frameEmpty],
            ],
            'every warning, in order' => [
                ['frame-src' => [], 'style-src' => 'unsafe-inline', 'script-src' => 'unsafe-inline'],
                [$noDefault, $scriptInline, $styleInline, $objectSrc, $frameEmpty],
            ],
        ];
    }

    #[DataProvider('lintProvider')]
    public function testLint(array $policy, array $expected): void
    {
        $this->assertSame($expected, (new Policy($policy))->lint());
    }

    // --- 1.1.2 ---

    public static function seedProvider(): array
    {
        return [
            'a new fetch directive starts from default-src' => [
                ['default-src' => 'self'], 'script-src', 'https://cdn.example.com',
                "default-src 'self'; script-src 'self' https://cdn.example.com;",
            ],
            "default-src 'none' seeds nothing visible" => [
                ['default-src' => 'none'], 'script-src', 'https://cdn.example.com',
                "default-src 'none'; script-src https://cdn.example.com;",
            ],
            'several default sources are carried over' => [
                ['default-src' => ['self', 'data']], 'img-src', 'https://img.example.com',
                "default-src 'self' data:; img-src 'self' data: https://img.example.com;",
            ],
            'a directive that does not fall back to default-src is not seeded' => [
                ['default-src' => 'self'], 'form-action', 'https://pay.example.com',
                "default-src 'self'; form-action https://pay.example.com;",
            ],
            'without default-src nothing is seeded' => [
                [], 'script-src', 'https://cdn.example.com',
                'script-src https://cdn.example.com;',
            ],
            'an existing directive is not re-seeded' => [
                ['default-src' => 'self', 'script-src' => 'https://a.example.com'], 'script-src', 'https://b.example.com',
                "default-src 'self'; script-src 'self' https://a.example.com https://b.example.com;",
            ],
        ];
    }

    /**
     * A fetch directive created on the fly stops the default-src fallback for its
     * resources: it starts from default-src's sources (it started empty, so an
     * integration or allowInline() blocked the site's own scripts).
     */
    #[DataProvider('seedProvider')]
    public function testAddSeedsANewFetchDirectiveFromDefaultSrc(array $initial, string $directive, string $sources, string $expected): void
    {
        $this->assertSame($expected, (new Policy($initial))->add($directive, $sources)->build());
    }

    public function testSetDoesNotSeed(): void
    {
        $this->assertSame(
            "default-src 'self'; script-src https://cdn.example.com;",
            (new Policy(['default-src' => 'self']))->set('script-src', 'https://cdn.example.com')->build()
        );
    }

    public function testADefaultSrcAddedLaterIsNotRetroactive(): void
    {
        $this->assertSame(
            "default-src 'self'; script-src https://cdn.example.com;",
            (new Policy())->add('script-src', 'https://cdn.example.com')->add('default-src', 'self')->build()
        );
    }

    /** report-to names one reporting group (several were emitted, which browsers refuse): the last one given */
    public function testReportToKeepsOneGroupTheLastOne(): void
    {
        $this->assertSame('report-to b;', (new Policy())->add('report-to', 'a')->add('report-to', 'b')->build());
        $this->assertSame('report-to b;', (new Policy())->add('report-to', 'a b')->build());
    }

    public function testLintWarnsOnAFetchDirectiveWithoutSelfWhileDefaultSrcHasIt(): void
    {
        $policy = (new Policy(['default-src' => 'self', 'object-src' => 'none']))->set('script-src', 'https://cdn.example.com');

        $this->assertSame(
            ["script-src does not include 'self' while default-src does — the site's own resources are blocked for it."],
            $policy->lint()
        );
    }

    public function testLintWarnsOnUnsafeInlineNextToANonceOrHash(): void
    {
        $policy = new Policy(['default-src' => 'self', 'object-src' => 'none', 'script-src' => ['self', 'unsafe-inline', 'nonce-abc'], 'style-src' => ['self', 'unsafe-inline', 'sha256-AbC=']]);

        $this->assertSame(
            [
                "script-src allows 'unsafe-inline' — weakens XSS protection.",
                "style-src allows 'unsafe-inline' — weakens XSS protection.",
                "script-src has 'unsafe-inline' next to a nonce or hash — browsers that support them ignore 'unsafe-inline'.",
                "style-src has 'unsafe-inline' next to a nonce or hash — browsers that support them ignore 'unsafe-inline'.",
            ],
            $policy->lint()
        );
    }

    public function testLintWarnsOnAnEmptyReportTo(): void
    {
        $policy = new Policy(['default-src' => 'self', 'object-src' => 'none', 'report-to' => []]);

        $this->assertSame(['report-to is present but empty — it will be dropped from output.'], $policy->lint());
    }
}
