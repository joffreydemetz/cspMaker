<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Integration\Matomo;
use JDZ\CspMaker\Integration\Registry;
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

    public function testMissingHostThrows(): void
    {
        $this->expectException(CspMakerException::class);
        (new Matomo())->apply(new Policy(), []);
    }

    public function testRegistryResolvesBuiltins(): void
    {
        $registry = new Registry();

        $this->assertTrue($registry->has('matomo'));
        $this->assertTrue($registry->has('googleFonts'));
        $this->assertContains('matomo', $registry->names());
    }
}
