<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker\Integration;

use JDZ\CspMaker\Exception\CspMakerException;

/**
 * Name => {@see Integration} lookup, seeded with the built-in recipes.
 *
 * Consumers register their own with {@see Registry::register()} — a saturn
 * plugin, say, can ship its CSP needs as an integration instead of a hand-kept
 * host list.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Registry
{
    /** @var array<string,Integration> */
    private array $integrations = [];

    public function __construct()
    {
        foreach ([
            new Matomo(),
            new GoogleFonts(),
            new GoogleAnalytics(),
            new GoogleTagManager(),
            new Youtube(),
            new Recaptcha(),
            new Stripe(),
        ] as $integration) {
            $this->register($integration);
        }
    }

    public function register(Integration $integration): self
    {
        $this->integrations[$integration->name()] = $integration;
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->integrations[$name]);
    }

    /** @throws CspMakerException when the name is not registered. */
    public function get(string $name): Integration
    {
        if (!isset($this->integrations[$name])) {
            throw new CspMakerException(sprintf(
                'Unknown CSP integration "%s". Registered: %s.',
                $name,
                implode(', ', $this->names())
            ));
        }

        return $this->integrations[$name];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->integrations);
    }
}
