<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Integration\Matomo;
use JDZ\CspMaker\Exception\CspMakerException;

final class MatomoTest extends TestCase
{
    public function testAppliesHostToScriptImgConnectFrame(): void
    {
        $policy = new Policy();
        (new Matomo())->apply($policy, ['host' => 'piwik.example.com']);

        $this->assertContains('piwik.example.com', $policy->get('script-src'));
        $this->assertContains('piwik.example.com', $policy->get('img-src'));
        $this->assertContains('piwik.example.com', $policy->get('connect-src'));
        $this->assertContains('piwik.example.com', $policy->get('frame-src'));
    }

    public function testPositionalHostOption(): void
    {
        $policy = new Policy();
        (new Matomo())->apply($policy, ['piwik.example.com']);

        $this->assertContains('piwik.example.com', $policy->get('script-src'));
    }

    public static function hostVariantsProvider(): array
    {
        return [
            'host option trimmed' => [['host' => '  stats.example.com  ']],
            'host option wins over positional' => [['host' => 'stats.example.com', 0 => 'other.example.com']],
            'positional trimmed' => [[' stats.example.com ']],
        ];
    }

    #[DataProvider('hostVariantsProvider')]
    public function testHostVariantsResolveToOneTrimmedHost(array $options): void
    {
        $policy = new Policy();
        (new Matomo())->apply($policy, $options);

        $this->assertSame([
            'connect-src' => ['stats.example.com'],
            'frame-src' => ['stats.example.com'],
            'img-src' => ['stats.example.com'],
            'script-src' => ['stats.example.com'],
        ], $policy->toArray());
    }

    public static function missingHostProvider(): array
    {
        return [
            'no options' => [[]],
            'empty host' => [['host' => '']],
            'blank host' => [['host' => '   ']],
            'empty positional' => [['']],
        ];
    }

    #[DataProvider('missingHostProvider')]
    public function testMissingHostThrows(array $options): void
    {
        $this->expectException(CspMakerException::class);
        $this->expectExceptionMessage('The matomo integration requires a host, e.g. ["host" => "piwik.example.com"].');
        (new Matomo())->apply(new Policy(), $options);
    }
}
