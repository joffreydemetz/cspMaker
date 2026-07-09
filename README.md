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
- **`lint()`** — advisories (missing `default-src`, `'unsafe-inline'`, unconstrained `object-src`).

## Used by

[`jdz/htaccessmaker`](https://jdz.joffreydemetz.com/htaccessmaker)'s `CspContainer`
delegates to cspMaker to emit the `Content-Security-Policy` header.
