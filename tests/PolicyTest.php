<?php

declare(strict_types=1);

namespace Tests;

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

    public function testUnknownDirectiveThrows(): void
    {
        $this->expectException(CspMakerException::class);
        (new Policy())->add('scriptt-src', 'self');
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

    public function testLintFlagsUnsafeInlineAndMissingDefaultSrc(): void
    {
        $warnings = (new Policy())->add('script-src', ['self', 'unsafe-inline'])->lint();

        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('default-src', $joined);
        $this->assertStringContainsString("'unsafe-inline'", $joined);
    }
}
