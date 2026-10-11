<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\CspBuilder;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Exception\CspMakerException;
use JDZ\CspMaker\Integration\Integration;
use JDZ\CspMaker\Integration\Registry;

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

        $this->assertSame(
            "default-src 'none'; connect-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';",
            $csp
        );
    }

    public function testAllowHostSpreadsAcrossDirectives(): void
    {
        $csp = CspBuilder::create()
            ->allowHost('https://cdn.example.com', ['script', 'style', 'img'])
            ->build();

        $this->assertSame('img-src https://cdn.example.com; script-src https://cdn.example.com; style-src https://cdn.example.com;', $csp);
    }

    public function testFromArrayFlatMap(): void
    {
        $csp = CspBuilder::fromArray([
            'default-src' => "'self'",
            'script-src' => "'self' 'unsafe-inline'",
        ])->build();

        $this->assertSame("default-src 'self'; script-src 'self' 'unsafe-inline';", $csp);
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

        // matomo host landed in every directive it needs — none forgotten; googleFonts in style + font
        $this->assertSame(
            "default-src 'none'; connect-src 'self' stats.example.com; font-src https://fonts.gstatic.com; frame-src stats.example.com; "
            . "img-src 'self' data: stats.example.com; script-src 'self' 'unsafe-inline' stats.example.com; style-src https://fonts.googleapis.com;",
            $csp
        );
    }

    public function testFromArrayKeepsRestrictiveDirectivesWithoutTokens(): void
    {
        $this->assertSame(
            'sandbox; trusted-types;',
            CspBuilder::fromArray(['policy' => ['sandbox' => true, 'trusted-types' => '']])->build()
        );
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
            $this->assertSame(
                "default-src 'self'; connect-src stats.example.com; frame-src stats.example.com; img-src stats.example.com; script-src 'self' 'unsafe-inline' stats.example.com;",
                CspBuilder::fromYaml($tmp)->build()
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function testFromYamlNullStandaloneDirectiveIsOn(): void
    {
        $tmp = sys_get_temp_dir() . '/cspmaker_' . uniqid() . '.yml';
        file_put_contents($tmp, <<<YAML
        policy:
          default-src: [self]
          upgrade-insecure-requests: ~
        YAML);

        try {
            $this->assertSame("default-src 'self'; upgrade-insecure-requests;", CspBuilder::fromYaml($tmp)->build());
        } finally {
            @unlink($tmp);
        }
    }

    public function testUnknownIntegrationThrows(): void
    {
        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage('Unknown CSP integration "not-a-real-service". Registered: matomo, googleFonts, googleAnalytics, googleTagManager, youtube, recaptcha, stripe.');
        CspBuilder::create()->with('not-a-real-service');
    }

    public function testLockedDownDefaults(): void
    {
        $csp = CspBuilder::create()->lockedDownDefaults()->build();

        $this->assertSame("default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self';", $csp);
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

        // script-src, img-src, connect-src (and frame-src) all whitelist the Matomo host
        $this->assertSame(
            "default-src 'none'; connect-src 'self' stats.example.com; font-src 'self' data:; frame-src stats.example.com; "
            . "img-src 'self' data: stats.example.com; script-src 'self' 'unsafe-inline' stats.example.com; style-src 'self' 'unsafe-inline';",
            $csp
        );
    }

    public static function perDirectiveHelperProvider(): array
    {
        $two = ['self', 'https://cdn.example.com'];

        return [
            'defaultSrc' => ['defaultSrc', $two, "default-src 'self' https://cdn.example.com;"],
            'scriptSrc' => ['scriptSrc', $two, "script-src 'self' https://cdn.example.com;"],
            'styleSrc' => ['styleSrc', $two, "style-src 'self' https://cdn.example.com;"],
            'imgSrc' => ['imgSrc', $two, "img-src 'self' https://cdn.example.com;"],
            'fontSrc' => ['fontSrc', $two, "font-src 'self' https://cdn.example.com;"],
            'connectSrc' => ['connectSrc', $two, "connect-src 'self' https://cdn.example.com;"],
            'frameSrc' => ['frameSrc', $two, "frame-src 'self' https://cdn.example.com;"],
            'childSrc' => ['childSrc', $two, "child-src 'self' https://cdn.example.com;"],
            'mediaSrc' => ['mediaSrc', $two, "media-src 'self' https://cdn.example.com;"],
            'objectSrc' => ['objectSrc', $two, "object-src 'self' https://cdn.example.com;"],
            'manifestSrc' => ['manifestSrc', $two, "manifest-src 'self' https://cdn.example.com;"],
            'workerSrc' => ['workerSrc', $two, "worker-src 'self' https://cdn.example.com;"],
            'baseUri' => ['baseUri', $two, "base-uri 'self' https://cdn.example.com;"],
            'formAction' => ['formAction', $two, "form-action 'self' https://cdn.example.com;"],
            'frameAncestors' => ['frameAncestors', $two, "frame-ancestors 'self' https://cdn.example.com;"],
            'reportUri' => ['reportUri', ['/csp-report'], 'report-uri /csp-report;'],
            'reportTo' => ['reportTo', ['csp-endpoint'], 'report-to csp-endpoint;'],
            'upgradeInsecureRequests' => ['upgradeInsecureRequests', [], 'upgrade-insecure-requests;'],
        ];
    }

    #[DataProvider('perDirectiveHelperProvider')]
    public function testPerDirectiveHelper(string $method, array $args, string $expected): void
    {
        $this->assertSame($expected, CspBuilder::create()->$method(...$args)->build());
    }

    public static function convenienceHelperProvider(): array
    {
        return [
            'per-directive helpers add, never replace' => [
                static fn (CspBuilder $b) => $b->scriptSrc('self')->scriptSrc('https://cdn.example.com', 'self'),
                "script-src 'self' https://cdn.example.com;",
            ],
            'upgradeInsecureRequests(false) removes it' => [
                static fn (CspBuilder $b) => $b->upgradeInsecureRequests()->upgradeInsecureRequests(false),
                '',
            ],
            'add with no sources turns a standalone directive on' => [
                static fn (CspBuilder $b) => $b->add('upgrade-insecure-requests'),
                'upgrade-insecure-requests;',
            ],
            'set with no sources turns a standalone directive on' => [
                static fn (CspBuilder $b) => $b->set('upgrade-insecure-requests'),
                'upgrade-insecure-requests;',
            ],
            'merge null turns a standalone directive on' => [
                static fn (CspBuilder $b) => $b->merge(['upgrade-insecure-requests' => null]),
                'upgrade-insecure-requests;',
            ],
            'allowInline defaults to script-src and style-src' => [
                static fn (CspBuilder $b) => $b->allowInline(),
                "script-src 'unsafe-inline'; style-src 'unsafe-inline';",
            ],
            'allowInline on the given directives only' => [
                static fn (CspBuilder $b) => $b->allowInline('style-attr'),
                "style-src-attr 'unsafe-inline';",
            ],
            'allowEval defaults to script-src' => [
                static fn (CspBuilder $b) => $b->allowEval(),
                "script-src 'unsafe-eval';",
            ],
            'allowEval on the given directives only' => [
                static fn (CspBuilder $b) => $b->allowEval('script', 'worker'),
                "script-src 'unsafe-eval'; worker-src 'unsafe-eval';",
            ],
            'allowHost with no directives adds nothing' => [
                static fn (CspBuilder $b) => $b->allowHost('https://cdn.example.com', []),
                '',
            ],
            'set replaces the sources' => [
                static fn (CspBuilder $b) => $b->scriptSrc('self', 'unsafe-inline')->set('script', 'nonce-abc'),
                "script-src 'nonce-abc';",
            ],
            'remove drops one source' => [
                static fn (CspBuilder $b) => $b->scriptSrc('self', 'unsafe-inline')->remove('script-src', 'unsafe-inline'),
                "script-src 'self';",
            ],
            'remove drops the whole directive' => [
                static fn (CspBuilder $b) => $b->defaultSrc('self')->scriptSrc('self')->remove('script'),
                "default-src 'self';",
            ],
            'merge adds a directive map' => [
                static fn (CspBuilder $b) => $b->defaultSrc('self')->merge(['img' => ['self', 'data'], 'default-src' => 'https://cdn.example.com']),
                "default-src 'self' https://cdn.example.com; img-src 'self' data:;",
            ],
        ];
    }

    #[DataProvider('convenienceHelperProvider')]
    public function testConvenienceHelper(\Closure $steps, string $expected): void
    {
        $this->assertSame($expected, $steps(CspBuilder::create())->build());
    }

    public static function integrationHelperProvider(): array
    {
        return [
            'matomo' => ['matomo', ['stats.example.com'],
                "default-src 'self'; connect-src 'self' stats.example.com; font-src 'self'; frame-src stats.example.com; "
                . "img-src 'self' data: stats.example.com; object-src 'none'; "
                . "script-src 'self' https://www.googletagmanager.com stats.example.com 'nonce-r4nd0m'; style-src 'self';",
            ],
            'googleFonts' => ['googleFonts', [],
                "default-src 'self'; connect-src 'self'; font-src 'self' https://fonts.gstatic.com; frame-src 'none'; "
                . "img-src 'self' data:; object-src 'none'; "
                . "script-src 'self' https://www.googletagmanager.com 'nonce-r4nd0m'; style-src 'self' https://fonts.googleapis.com;",
            ],
            'googleAnalytics' => ['googleAnalytics', [],
                "default-src 'self'; connect-src 'self' https://analytics.google.com https://www.google-analytics.com https://www.googletagmanager.com; "
                . "font-src 'self'; frame-src 'none'; img-src 'self' data: https://www.google-analytics.com https://www.googletagmanager.com; "
                . "object-src 'none'; script-src 'self' https://www.google-analytics.com https://www.googletagmanager.com 'nonce-r4nd0m'; style-src 'self';",
            ],
            'googleTagManager' => ['googleTagManager', [],
                "default-src 'self'; connect-src 'self' https://www.googletagmanager.com; font-src 'self'; frame-src 'none'; "
                . "img-src 'self' data: https://www.googletagmanager.com; object-src 'none'; "
                . "script-src 'self' https://www.googletagmanager.com 'nonce-r4nd0m'; style-src 'self';",
            ],
            'youtube' => ['youtube', [],
                "default-src 'self'; connect-src 'self'; font-src 'self'; frame-src https://www.youtube-nocookie.com https://www.youtube.com; "
                . "img-src 'self' data: https://i.ytimg.com; object-src 'none'; "
                . "script-src 'self' https://www.googletagmanager.com 'nonce-r4nd0m'; style-src 'self';",
            ],
            'recaptcha' => ['recaptcha', [],
                "default-src 'self'; connect-src 'self'; font-src 'self'; frame-src https://www.google.com; "
                . "img-src 'self' data:; object-src 'none'; "
                . "script-src 'self' https://www.google.com https://www.googletagmanager.com https://www.gstatic.com 'nonce-r4nd0m'; style-src 'self';",
            ],
            'stripe' => ['stripe', [],
                "default-src 'self'; connect-src 'self' https://api.stripe.com; font-src 'self'; frame-src https://hooks.stripe.com https://js.stripe.com; "
                . "img-src 'self' data:; object-src 'none'; "
                . "script-src 'self' https://js.stripe.com https://www.googletagmanager.com 'nonce-r4nd0m'; style-src 'self';",
            ],
        ];
    }

    /**
     * On top of a nonce-based base policy: sources already present are not
     * repeated, the nonce stays last, a frame-src 'none' gives way to the
     * integration's hosts, and directives it does not touch are left alone.
     */
    #[DataProvider('integrationHelperProvider')]
    public function testIntegrationHelperOnTopOfABasePolicy(string $method, array $args, string $expected): void
    {
        $builder = CspBuilder::fromArray([
            'default-src' => 'self',
            'script-src' => ['self', 'nonce-r4nd0m', 'https://www.googletagmanager.com'],
            'style-src' => 'self',
            'font-src' => 'self',
            'img-src' => ['self', 'data'],
            'connect-src' => 'self',
            'frame-src' => 'none',
            'object-src' => 'none',
        ]);

        $this->assertSame($expected, $builder->$method(...$args)->build());
    }

    public static function integrationsConfigProvider(): array
    {
        $fonts = 'font-src https://fonts.gstatic.com; style-src https://fonts.googleapis.com;';
        $matomo = 'connect-src stats.example.com; frame-src stats.example.com; img-src stats.example.com; script-src stats.example.com;';

        return [
            'bare name' => [['googleFonts'], $fonts],
            'name => null' => [[['googleFonts' => null]], $fonts],
            'name => true' => [[['googleFonts' => true]], $fonts],
            'name => positional value' => [[['matomo' => 'stats.example.com']], $matomo],
            'name => options array' => [[['matomo' => ['host' => 'stats.example.com']]], $matomo],
            'several names in one entry' => [
                [['matomo' => 'stats.example.com', 'stripe' => null]],
                'connect-src https://api.stripe.com stats.example.com; frame-src https://hooks.stripe.com https://js.stripe.com stats.example.com; '
                . 'img-src stats.example.com; script-src https://js.stripe.com stats.example.com;',
            ],
            'name => false is skipped' => [[['googleFonts' => false]], ''],
            'name => false skips only that name' => [[['googleFonts' => false, 'matomo' => 'stats.example.com']], $matomo],
            'map: name => null' => [['googleFonts' => null], $fonts],
            'map: name => true' => [['googleFonts' => true], $fonts],
            'map: name => positional value' => [['matomo' => 'stats.example.com'], $matomo],
            'map: name => options array' => [['matomo' => ['host' => 'stats.example.com']], $matomo],
            'map: name => false is skipped, its options never checked' => [['matomo' => false], ''],
            'map: every value form' => [
                ['googleFonts' => null, 'youtube' => true, 'matomo' => 'stats.example.com', 'stripe' => false],
                'connect-src stats.example.com; font-src https://fonts.gstatic.com; '
                . 'frame-src https://www.youtube-nocookie.com https://www.youtube.com stats.example.com; '
                . 'img-src https://i.ytimg.com stats.example.com; script-src stats.example.com; style-src https://fonts.googleapis.com;',
            ],
            'list entries and map entries mixed' => [
                ['googleFonts', 'matomo' => 'stats.example.com'],
                'connect-src stats.example.com; font-src https://fonts.gstatic.com; frame-src stats.example.com; '
                . 'img-src stats.example.com; script-src stats.example.com; style-src https://fonts.googleapis.com;',
            ],
        ];
    }

    #[DataProvider('integrationsConfigProvider')]
    public function testIntegrationsConfigForms(array $list, string $expected): void
    {
        $this->assertSame($expected, CspBuilder::create()->integrations($list)->build());
    }

    public function testFromArrayStructuredWithIntegrationsOnly(): void
    {
        $this->assertSame(
            'connect-src https://api.stripe.com; frame-src https://hooks.stripe.com https://js.stripe.com; script-src https://js.stripe.com;',
            CspBuilder::fromArray(['integrations' => ['stripe']])->build()
        );
    }

    public function testFromArraySkipsAnIntegrationSetToFalse(): void
    {
        $this->assertSame('', CspBuilder::fromArray(['integrations' => [['googleFonts' => false]]])->build());
    }

    public function testFromYamlIntegrationsMap(): void
    {
        $tmp = sys_get_temp_dir() . '/cspmaker_' . uniqid() . '.yml';
        file_put_contents($tmp, <<<YAML
        policy:
          default-src: [self]
        integrations:
          googleFonts: ~
          youtube: true
          matomo: stats.example.com
          stripe: false
        YAML);

        try {
            $this->assertSame(
                "default-src 'self'; connect-src stats.example.com; font-src https://fonts.gstatic.com; "
                . 'frame-src https://www.youtube-nocookie.com https://www.youtube.com stats.example.com; '
                . 'img-src https://i.ytimg.com stats.example.com; script-src stats.example.com; style-src https://fonts.googleapis.com;',
                CspBuilder::fromYaml($tmp)->build()
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function testRegisteredIntegrationReceivesItsOptions(): void
    {
        $csp = CspBuilder::create()
            ->registerIntegration(self::widget())
            ->with('widget', ['host' => 'cdn.example.com'])
            ->build();

        $this->assertSame('img-src cdn.example.com;', $csp);
    }

    public function testFromArrayResolvesIntegrationsInTheGivenRegistry(): void
    {
        $registry = (new Registry())->register(self::widget());

        $this->assertSame(
            "default-src 'self'; img-src widget.example.com;",
            CspBuilder::fromArray(['policy' => ['default-src' => 'self'], 'integrations' => ['widget']], $registry)->build()
        );
    }

    public function testBuilderWritesIntoTheGivenPolicy(): void
    {
        $policy = new Policy(['default-src' => 'self']);
        (new CspBuilder($policy))->scriptSrc('self');

        $this->assertSame("default-src 'self'; script-src 'self';", $policy->build());
    }

    public function testCastToStringIsTheHeaderValue(): void
    {
        $this->assertSame(
            "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self';",
            (string) CspBuilder::create()->lockedDownDefaults()
        );
    }

    /** 1.1.2: allowInline() on a locked-down policy keeps the site's own scripts (the new directives started empty) */
    public function testAllowInlineAfterLockedDownDefaultsKeepsSelf(): void
    {
        $this->assertSame(
            "default-src 'self'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; base-uri 'self'; frame-ancestors 'self';",
            CspBuilder::create()->lockedDownDefaults()->allowInline()->build()
        );
    }

    public function testAnIntegrationOnALockedDownPolicyKeepsSelf(): void
    {
        $this->assertStringContainsString(
            "script-src 'self' piwik.example.com;",
            CspBuilder::create()->lockedDownDefaults()->matomo('piwik.example.com')->build()
        );
    }

    public function testReportToTwiceKeepsTheLastGroup(): void
    {
        $this->assertSame('report-to b;', CspBuilder::create()->reportTo('a')->reportTo('b')->build());
    }

    public function testLintReportsThePolicyWarnings(): void
    {
        $this->assertSame(
            [
                "script-src allows 'unsafe-inline' — weakens XSS protection.",
                "style-src allows 'unsafe-inline' — weakens XSS protection.",
            ],
            CspBuilder::create()->lockedDownDefaults()->allowInline()->lint()
        );
    }

    public function testFromYamlMissingFileThrows(): void
    {
        $path = __DIR__ . '/missing-csp.yml';

        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage('CSP config file not found: ' . $path);
        CspBuilder::fromYaml($path);
    }

    public static function injectedTokenProvider(): array
    {
        return [
            'semicolon in a per-directive helper injects a directive' => [
                static fn (CspBuilder $b) => $b->imgSrc('cdn.example.com;script-src', 'evil.example.com'),
                'cdn.example.com;script-src',
            ],
            'semicolon then space in a config map' => [
                static fn (CspBuilder $b) => $b->merge(['img-src' => 'cdn.example.com; script-src https://evil.example.com']),
                'cdn.example.com;',
            ],
            'comma in a source list starts a second policy' => [
                static fn (CspBuilder $b) => $b->scriptSrc("'self',https://evil.example.com"),
                "'self',https://evil.example.com",
            ],
            'control character in a source list' => [
                static fn (CspBuilder $b) => $b->add('img', "cdn.example.com\x1Bevil"),
                'cdn.example.com\033evil',
            ],
            'semicolon in a value directive' => [
                static fn (CspBuilder $b) => $b->reportUri('/csp-report;script-src'),
                '/csp-report;script-src',
            ],
            'comma in a sandbox token' => [
                static fn (CspBuilder $b) => $b->add('sandbox', 'allow-scripts,allow-forms'),
                'allow-scripts,allow-forms',
            ],
            'matomo host from config' => [
                static fn (CspBuilder $b) => $b->integrations([['matomo' => 'stats.example.com;script-src']]),
                'stats.example.com;script-src',
            ],
            'matomo host through its helper' => [
                static fn (CspBuilder $b) => $b->matomo('stats.example.com,'),
                'stats.example.com,',
            ],
        ];
    }

    #[DataProvider('injectedTokenProvider')]
    public function testInjectedTokenThrows(\Closure $steps, string $reported): void
    {
        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage(sprintf('Invalid CSP token "%s": ";", "," and control characters are not allowed.', $reported));
        $steps(CspBuilder::create());
    }

    /** Adds its "host" option (default widget.example.com) to img-src. */
    private static function widget(): Integration
    {
        return new class implements Integration {
            public function name(): string
            {
                return 'widget';
            }

            public function apply(Policy $policy, array $options = []): void
            {
                $policy->add('img-src', (string) ($options['host'] ?? 'widget.example.com'));
            }
        };
    }
}
