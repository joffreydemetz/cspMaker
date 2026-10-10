<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Integration\GoogleAnalytics;
use JDZ\CspMaker\Integration\GoogleFonts;
use JDZ\CspMaker\Integration\GoogleTagManager;
use JDZ\CspMaker\Integration\Matomo;
use JDZ\CspMaker\Integration\Recaptcha;
use JDZ\CspMaker\Integration\Stripe;
use JDZ\CspMaker\Integration\Youtube;

final class BuiltinIntegrationsTest extends TestCase
{
    public static function emptyPolicyProvider(): array
    {
        return [
            'googleAnalytics' => [GoogleAnalytics::class, [], [
                'connect-src' => ['https://analytics.google.com', 'https://www.google-analytics.com', 'https://www.googletagmanager.com'],
                'img-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
                'script-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
            ]],
            'googleFonts' => [GoogleFonts::class, [], [
                'font-src' => ['https://fonts.gstatic.com'],
                'style-src' => ['https://fonts.googleapis.com'],
            ]],
            'googleTagManager' => [GoogleTagManager::class, [], [
                'connect-src' => ['https://www.googletagmanager.com'],
                'img-src' => ['https://www.googletagmanager.com'],
                'script-src' => ['https://www.googletagmanager.com'],
            ]],
            'matomo' => [Matomo::class, ['host' => 'stats.example.com'], [
                'connect-src' => ['stats.example.com'],
                'frame-src' => ['stats.example.com'],
                'img-src' => ['stats.example.com'],
                'script-src' => ['stats.example.com'],
            ]],
            'recaptcha' => [Recaptcha::class, [], [
                'frame-src' => ['https://www.google.com'],
                'script-src' => ['https://www.google.com', 'https://www.gstatic.com'],
            ]],
            'stripe' => [Stripe::class, [], [
                'connect-src' => ['https://api.stripe.com'],
                'frame-src' => ['https://hooks.stripe.com', 'https://js.stripe.com'],
                'script-src' => ['https://js.stripe.com'],
            ]],
            'youtube' => [Youtube::class, [], [
                'frame-src' => ['https://www.youtube-nocookie.com', 'https://www.youtube.com'],
                'img-src' => ['https://i.ytimg.com'],
            ]],
        ];
    }

    /**
     * @param class-string<\JDZ\CspMaker\Integration\Integration> $class
     */
    #[DataProvider('emptyPolicyProvider')]
    public function testAddsExactDirectivesToAnEmptyPolicy(string $class, array $options, array $expected): void
    {
        $policy = new Policy();
        (new $class())->apply($policy, $options);

        $this->assertSame($expected, $policy->toArray());
    }
}
