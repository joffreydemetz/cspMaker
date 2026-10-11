# jdz/cspmaker

Build **Content Security Policy** header strings without forgetting a directive.

The whole point: a third party like Matomo needs to be whitelisted in `script-src`
**and** `img-src` **and** `connect-src`. Hand-list those and you will eventually
forget one — and the browser silently blocks the request. cspMaker gives you one
source of truth for the CSP directive vocabulary, plus reusable **integration
recipes** that know every directive a service touches.

```
composer require jdz/cspmaker
```

Requires PHP 8.2+. Zero runtime dependencies (`symfony/yaml` is only needed for
`CspBuilder::fromYaml()`).

## Three ways in, one header out

### 1. Fluent builder

```php
use JDZ\CspMaker\CspBuilder;

$csp = CspBuilder::create()
    ->defaultSrc("'none'")
    ->scriptSrc('self', 'unsafe-inline')
    ->styleSrc('self', 'unsafe-inline')
    ->fontSrc('self', 'data')
    ->imgSrc('self', 'data')
    ->connectSrc('self')
    ->matomo('piwik.example.com')   // script + img + connect + frame, atomically
    ->build();

// default-src 'none'; script-src 'self' 'unsafe-inline' piwik.example.com; …
```

`->allowHost($host, ['script','img','connect'])` is the low-level primitive when
there's no recipe: one host across many directives in a single call.

### 2. Config array or YAML file

```php
$csp = CspBuilder::fromYaml('config/csp.yml')->build();
```

```yaml
# config/csp.yml
policy:
  default-src: [self]
  script-src:  [self, unsafe-inline]
  img-src:     [self, data]
  connect-src: [self]
integrations:
  - matomo: piwik.example.com
  - googleFonts
```

`fromArray()` accepts the same structured shape, or a flat `directive => sources` map.

### 3. Named integration recipes

`matomo($host)`, `googleFonts()`, `googleAnalytics()`, `googleTagManager()`,
`youtube()`, `recaptcha()`, `stripe()` — each expands to exactly the directives
that service requires. Ship your own by implementing `Integration` and
registering it:

```php
CspBuilder::create()->registerIntegration(new MyWidget())->with('my-widget');
```

## Niceties

- **Short aliases** — `script` ↔ `script-src`, `img` ↔ `img-src`, …
- **Token normalization** — `self` → `'self'`, `data` → `data:`, nonces/hashes quoted.
- **Validation** — adding a source to a misspelled directive throws instead of silently vanishing.
- **`'none'` collapse**, dedup, and a stable canonical render order.
- **`default-src` seeding** — a fetch directive created on the fly by `add()`, an integration or `allowInline()` starts from `default-src`'s sources (creating `script-src` would otherwise stop the `default-src` fallback and block the site's own scripts). Not with `set()`, not when `'none'` is added, not for `base-uri` / `form-action` / `frame-ancestors`, and only once `default-src` is there.
- **`report-to`** names one reporting group: the last one given.
- **`lint()`** — advisories: missing `default-src`; a fetch directive without `'self'` while `default-src` has it; `'unsafe-inline'`, and `'unsafe-inline'` next to a nonce or hash; unconstrained `object-src`; a directive present but empty (`report-to` included).

## Changelog

- **1.1.2** (2026-10-11) — a fetch directive created on the fly starts from `default-src`'s sources (it started empty: an integration or `allowInline()` blocked the site's own scripts; see *Niceties*); `report-to` keeps one group, the last given (several were emitted); `lint()` warns on a fetch directive without `'self'` while `default-src` has it, on `'unsafe-inline'` next to a nonce or hash, and on an empty `report-to`.

## Used by

[`jdz/htaccessmaker`](https://jdz.joffreydemetz.com/htaccessmaker)'s `CspContainer`
delegates to cspMaker to emit the `Content-Security-Policy` header.
