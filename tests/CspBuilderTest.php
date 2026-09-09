<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\CspBuilder;
use JDZ\CspMaker\Exception\CspMakerException;

final class CspBuilderTest extends TestCase
{
    public function testFluentPerDirectiveBuild(): void
    {
        $csp = CspBuilder::create()
            ->defaultSrc("'none'")
            ->scriptSrc('self', 'unsafe-inline')
            ->styleSrc('self', 'unsafe-inline')
            ->imgSrc('self', 'data')
            ->connectSrc('self')
            ->build();

        $this->assertStringContainsString("default-src 'none';", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline';", $csp);
        $this->assertStringContainsString("img-src 'self' data:;", $csp);
    }

    public function testAllowHostSpreadsAcrossDirectives(): void
    {
        $csp = CspBuilder::create()
            ->allowHost('https://cdn.example.com', ['script', 'style', 'img'])
            ->build();

        $this->assertStringContainsString('script-src https://cdn.example.com;', $csp);
        $this->assertStringContainsString('style-src https://cdn.example.com;', $csp);
        $this->assertStringContainsString('img-src https://cdn.example.com;', $csp);
    }

    public function testFromArrayFlatMap(): void
    {
        $csp = CspBuilder::fromArray([
            'default-src' => "'self'",
            'script-src' => "'self' 'unsafe-inline'",
        ])->build();

        $this->assertStringContainsString("default-src 'self';", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline';", $csp);
    }

    public function testFromArrayStructuredWithIntegrations(): void
    {
        $csp = CspBuilder::fromArray([
            'policy' => [
                'default-src' => 'none',
                'script-src' => ['self', 'unsafe-inline'],
                'img-src' => ['self', 'data'],
                'connect-src' => 'self',
            ],
            'integrations' => [
                ['matomo' => 'stats.example.com'],
                'googleFonts',
            ],
        ])->build();

        // matomo host landed in every directive it needs — none forgotten
        $this->assertStringContainsString('stats.example.com', $csp);
        $this->assertMatchesRegularExpression('/script-src[^;]*stats\.example\.com/', $csp);
        $this->assertMatchesRegularExpression('/img-src[^;]*stats\.example\.com/', $csp);
        $this->assertMatchesRegularExpression('/connect-src[^;]*stats\.example\.com/', $csp);
        // googleFonts
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
        $this->assertStringContainsString('https://fonts.gstatic.com', $csp);
    }

    public function testFromYaml(): void
    {
        $tmp = sys_get_temp_dir() . '/cspmaker_' . uniqid() . '.yml';
        file_put_contents($tmp, <<<YAML
        policy:
          default-src: [self]
          script-src: [self, unsafe-inline]
        integrations:
          - matomo: stats.example.com
        YAML);

        try {
            $csp = CspBuilder::fromYaml($tmp)->build();
            $this->assertStringContainsString("default-src 'self';", $csp);
            $this->assertStringContainsString('stats.example.com', $csp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUnknownIntegrationThrows(): void
    {
        $this->expectException(CspMakerException::class);
        CspBuilder::create()->with('not-a-real-service');
    }

    public function testLockedDownDefaults(): void
    {
        $csp = CspBuilder::create()->lockedDownDefaults()->build();

        $this->assertStringContainsString("default-src 'self';", $csp);
        $this->assertStringContainsString("object-src 'none';", $csp);
        $this->assertStringContainsString("base-uri 'self';", $csp);
        $this->assertStringContainsString("frame-ancestors 'self';", $csp);
    }

    /**
     * The exact Error 1 scenario: a site's base policy blocked Matomo
     * because the host was never whitelisted. Through cspMaker it can't be.
     */
    public function testError1MatomoScenarioIsCovered(): void
    {
        $base = [
            'default-src' => "'none'",
            'script-src' => ['self', 'unsafe-inline'],
            'style-src' => ['self', 'unsafe-inline'],
            'font-src' => ['self', 'data'],
            'connect-src' => ['self'],
            'img-src' => ['self', 'data'],
        ];

        $csp = CspBuilder::fromArray($base)->matomo('stats.example.com')->build();

        foreach (['script-src', 'img-src', 'connect-src'] as $d) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($d, '/') . '[^;]*stats\.example\.com/',
                $csp,
                "$d must whitelist the Matomo host"
            );
        }
    }
}
