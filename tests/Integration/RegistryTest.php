<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use JDZ\CspMaker\CspBuilder;
use JDZ\CspMaker\Policy;
use JDZ\CspMaker\Integration\Integration;
use JDZ\CspMaker\Integration\Registry;

final class RegistryTest extends TestCase
{
    private const BUILTINS = ['matomo', 'googleFonts', 'googleAnalytics', 'googleTagManager', 'youtube', 'recaptcha', 'stripe'];

    public function testSeedsTheSevenBuiltinsInOrder(): void
    {
        $this->assertSame(self::BUILTINS, (new Registry())->names());
    }

    public static function hasProvider(): array
    {
        return [
            'builtin matomo' => ['matomo', true],
            'builtin stripe' => ['stripe', true],
            'names are case-sensitive' => ['Matomo', false],
            'unregistered' => ['not-registered', false],
            'empty name' => ['', false],
        ];
    }

    #[DataProvider('hasProvider')]
    public function testHas(string $name, bool $expected): void
    {
        $this->assertSame($expected, (new Registry())->has($name));
    }

    public function testRegisterAppendsANewName(): void
    {
        $registry = (new Registry())->register(self::integration('widget', 'img-src', 'cdn.example.com'));

        $this->assertSame([...self::BUILTINS, 'widget'], $registry->names());
    }

    public function testRegisterReplacesTheBuiltinOfTheSameName(): void
    {
        $registry = (new Registry())->register(self::integration('googleFonts', 'font-src', 'fonts.example.com'));

        $this->assertSame(self::BUILTINS, $registry->names());
        $this->assertSame('font-src fonts.example.com;', CspBuilder::create($registry)->googleFonts()->build());
    }

    private static function integration(string $name, string $directive, string $source): Integration
    {
        return new class($name, $directive, $source) implements Integration {
            public function __construct(
                private readonly string $integrationName,
                private readonly string $directive,
                private readonly string $source,
            ) {}

            public function name(): string
            {
                return $this->integrationName;
            }

            public function apply(Policy $policy, array $options = []): void
            {
                $policy->add($this->directive, $this->source);
            }
        };
    }
}
