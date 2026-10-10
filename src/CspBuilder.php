<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\CspMaker;

use JDZ\CspMaker\Integration\Integration;
use JDZ\CspMaker\Integration\Registry;
use JDZ\CspMaker\Exception\CspMakerException;

/**
 * Fluent builder for a {@see Policy}.
 *
 * Three ways in, one way out:
 *   - chain the per-directive methods (->scriptSrc('self','unsafe-inline')),
 *   - hand it a config array or YAML file (::fromArray / ::fromYaml),
 *   - apply named integration recipes (->matomo($host), ->googleFonts()),
 * then ->build() (or a cast to string) for the header value.
 *
 * @author  Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class CspBuilder
{
    private Policy $policy;
    private Registry $registry;

    public function __construct(?Policy $policy = null, ?Registry $registry = null)
    {
        $this->policy = $policy ?? new Policy();
        $this->registry = $registry ?? new Registry();
    }

    public static function create(?Registry $registry = null): self
    {
        return new self(null, $registry);
    }

    /**
     * From a config array. Accepts either a flat directive map
     * (['script-src' => "'self'", …]) or a structured
     * (['policy' => […], 'integrations' => […]]) shape.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config, ?Registry $registry = null): self
    {
        $builder = new self(null, $registry);

        if (isset($config['policy']) || isset($config['integrations'])) {
            $builder->merge((array) ($config['policy'] ?? []));
            $builder->applyIntegrationsConfig((array) ($config['integrations'] ?? []));
            return $builder;
        }

        return $builder->merge($config);
    }

    /**
     * From a YAML config file (structured or flat, same as {@see fromArray}).
     *
     * @throws CspMakerException when the file is missing or symfony/yaml absent.
     */
    public static function fromYaml(string $path, ?Registry $registry = null): self
    {
        if (!is_file($path)) {
            throw new CspMakerException(sprintf('CSP config file not found: %s', $path));
        }

        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            throw new CspMakerException('CspBuilder::fromYaml() needs symfony/yaml — run "composer require symfony/yaml".');
        }

        return self::fromArray((array) \Symfony\Component\Yaml\Yaml::parseFile($path), $registry);
    }

    // -- generic -----------------------------------------------------------

    public function add(string $directive, string|array|bool|null $sources = null): self
    {
        $this->policy->add($directive, $sources);
        return $this;
    }

    public function set(string $directive, string|array|bool|null $sources = null): self
    {
        $this->policy->set($directive, $sources);
        return $this;
    }

    public function remove(string $directive, string|array|null $sources = null): self
    {
        $this->policy->remove($directive, $sources);
        return $this;
    }

    /** @param array<string,string|array|bool|null> $policy */
    public function merge(array $policy): self
    {
        $this->policy->merge($policy);
        return $this;
    }

    // -- per-directive sugar ----------------------------------------------

    public function defaultSrc(string ...$s): self { return $this->add('default-src', $s); }
    public function scriptSrc(string ...$s): self { return $this->add('script-src', $s); }
    public function styleSrc(string ...$s): self { return $this->add('style-src', $s); }
    public function imgSrc(string ...$s): self { return $this->add('img-src', $s); }
    public function fontSrc(string ...$s): self { return $this->add('font-src', $s); }
    public function connectSrc(string ...$s): self { return $this->add('connect-src', $s); }
    public function frameSrc(string ...$s): self { return $this->add('frame-src', $s); }
    public function childSrc(string ...$s): self { return $this->add('child-src', $s); }
    public function mediaSrc(string ...$s): self { return $this->add('media-src', $s); }
    public function objectSrc(string ...$s): self { return $this->add('object-src', $s); }
    public function manifestSrc(string ...$s): self { return $this->add('manifest-src', $s); }
    public function workerSrc(string ...$s): self { return $this->add('worker-src', $s); }
    public function baseUri(string ...$s): self { return $this->add('base-uri', $s); }
    public function formAction(string ...$s): self { return $this->add('form-action', $s); }
    public function frameAncestors(string ...$s): self { return $this->add('frame-ancestors', $s); }
    public function reportUri(string $uri): self { return $this->add('report-uri', $uri); }
    public function reportTo(string $group): self { return $this->add('report-to', $group); }

    public function upgradeInsecureRequests(bool $on = true): self
    {
        return $this->add('upgrade-insecure-requests', $on);
    }

    // -- convenience -------------------------------------------------------

    /** Add 'unsafe-inline' to the given directives (default: script + style). */
    public function allowInline(string ...$directives): self
    {
        foreach ($directives ?: ['script-src', 'style-src'] as $d) {
            $this->add($d, "'unsafe-inline'");
        }
        return $this;
    }

    /** Add 'unsafe-eval' to the given directives (default: script). */
    public function allowEval(string ...$directives): self
    {
        foreach ($directives ?: ['script-src'] as $d) {
            $this->add($d, "'unsafe-eval'");
        }
        return $this;
    }

    /**
     * Whitelist one host across several directives at once — the primitive that
     * stops you adding a third party to script-src and forgetting connect-src.
     *
     * @param string[] $directives
     */
    public function allowHost(string $host, array $directives): self
    {
        foreach ($directives as $d) {
            $this->add($d, $host);
        }
        return $this;
    }

    /**
     * A sane locked-down baseline: default-src 'self', object-src 'none',
     * base-uri 'self', frame-ancestors 'self'.
     */
    public function lockedDownDefaults(): self
    {
        return $this
            ->defaultSrc("'self'")
            ->objectSrc("'none'")
            ->baseUri("'self'")
            ->frameAncestors("'self'");
    }

    // -- integrations ------------------------------------------------------

    /**
     * Apply a registered integration recipe by name.
     *
     * @param array<string,mixed> $options
     */
    public function with(string $name, array $options = []): self
    {
        $this->registry->get($name)->apply($this->policy, $options);
        return $this;
    }

    public function registerIntegration(Integration $integration): self
    {
        $this->registry->register($integration);
        return $this;
    }

    /**
     * Apply integrations in config form — a list of "name" or ["name" => options],
     * or a map name => options (null/true = defaults, false = skip).
     *
     * @param array<int|string,mixed> $list
     */
    public function integrations(array $list): self
    {
        $this->applyIntegrationsConfig($list);
        return $this;
    }

    public function matomo(string $host): self { return $this->with('matomo', ['host' => $host]); }
    public function googleFonts(): self { return $this->with('googleFonts'); }
    public function googleAnalytics(): self { return $this->with('googleAnalytics'); }
    public function googleTagManager(): self { return $this->with('googleTagManager'); }
    public function youtube(): self { return $this->with('youtube'); }
    public function recaptcha(): self { return $this->with('recaptcha'); }
    public function stripe(): self { return $this->with('stripe'); }

    // -- output ------------------------------------------------------------

    public function policy(): Policy
    {
        return $this->policy;
    }

    public function build(): string
    {
        return $this->policy->build();
    }

    public function __toString(): string
    {
        return $this->policy->build();
    }

    /** @return string[] */
    public function lint(): array
    {
        return $this->policy->lint();
    }

    /**
     * Two shapes, freely mixed: list entries ("name" or ["name" => options]) and
     * map entries (name => options).
     *
     * @param array<int|string,mixed> $integrations
     */
    private function applyIntegrationsConfig(array $integrations): void
    {
        foreach ($integrations as $key => $entry) {
            if (is_string($key)) {
                $this->applyIntegrationEntry($key, $entry);
                continue;
            }

            if (is_string($entry)) {
                $this->with($entry);
                continue;
            }

            if (is_array($entry)) {
                foreach ($entry as $name => $opts) {
                    $this->applyIntegrationEntry((string) $name, $opts);
                }
            }
        }
    }

    /**
     * One name => options pair: null/true = the recipe's defaults, false = skip
     * it, an array = its options, any other value = its first positional option.
     */
    private function applyIntegrationEntry(string $name, mixed $opts): void
    {
        if (false === $opts) {
            return;
        }

        $options = is_array($opts)
            ? $opts
            : (null === $opts || true === $opts ? [] : [$opts]);
        $this->with($name, $options);
    }
}
