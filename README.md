# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)
[![Documentation](https://img.shields.io/badge/Documentation-InterMix-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/intermix/)

`InterMix` is a lightweight, high-performance PHP dependency injection and runtime utility toolkit. Dependency injection and invocation are the primary capabilities, supported by compiled resolution, Closure serialization, Fence, and fluent Remix utilities.

> Global helper functions are optional: core APIs are namespaced and helper loading is opt-in.

## Key Features

- **Dependency Injection (DI)** — PSR-11 compliant container with:
  - attribute-based injection
  - scoped lifetimes
  - lazy loading
  - environment-specific overrides
  - debug tracing & definition-cache integration via assignable PSR-6 pool
- **Closure Serialization** — Versioned unsigned and explicitly signed Closure payloads
- **Fence** — Enforce singleton-style class safety
- **Remix** — Fluent traits, proxies, and global helper functions
- **MacroMix** — Dynamically extend objects or classes with macros
- **Global Utilities** — Like `pipe()`, `retry()`, `measure()` and more

## Installation

```bash
composer require infocyph/intermix
```

Optional global helpers:

```php
require_once __DIR__ . '/vendor/infocyph/intermix/src/functions.php';
```

Current InterMix releases require PHP 8.4 or newer, as declared by `composer.json`.

## Quick Examples

### Dependency Injection

```php
use function Infocyph\InterMix\container;

$c = container();
$c->definitions()->bind('now', fn () => new DateTimeImmutable());

echo $c->get('now')->format('c');
```

Enable autowiring with attributes:

```php
$c->options()->setOptions(
    injection: true,
    methodAttributes: true,
    propertyAttributes: true
);
```

Tag-based resolution:

```php
$c->definitions()->bind('a', A::class, tags: ['service']);
$c->definitions()->bind('b', B::class, tags: ['service']);

foreach ($c->findByTag('service') as $svc) {
    $svc->handle();
}
```

Reflection-free factories:

```php
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

$c->bindFactory(
    Database::class,
    static fn (Container $container): Database => new Database(
        $container->get(DatabaseConfig::class),
    ),
    LifetimeEnum::Singleton,
    tags: ['infrastructure'],
);

// Equivalent fluent lifetime selection:
$c->factory(
    RequestContext::class,
    static fn (Container $container): RequestContext => new RequestContext(
        $container->get('request.id'),
    ),
)->scoped();
```

Use a regular closure definition when its parameters should be autowired. Use
`bindFactory()` or `factory()` when dependencies are explicit and request-time
reflection should be avoided. Direct factories behave identically whether
container injection is enabled or disabled.

See full container guide at: [https://docs.infocyph.com/projects/intermix/di/overview.html](https://docs.infocyph.com/projects/intermix/di/overview.html)

### Dynamic Macros

```php
MacroTest::mix(new class {
    public function hello($name) {
        return "Hey, $name!";
    }
});

echo (new MacroTest)->hello('Ali'); // Hey, Ali!
```

### Definition Cache (Injectable)

```php
use Psr\Cache\CacheItemPoolInterface;

$pool = /* any PSR-6 pool, e.g. from infocyph/Intermix */;
$c->definitions()->enableDefinitionCache($pool, cacheRuntimeObjects: false);
```

### Compiled Resolvers

```php
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

$path = __DIR__ . '/var/intermix.compiled.php';

// Explicit recipes can be executed dynamically and compiled safely.
$c->bind('mailer', FactoryDefinition::construct(Mailer::class, [
    new ServiceReference(Logger::class),
    'transactional',
]));

// Build time, after every definition and option is registered.
$c->compileTo($path);
$report = $c->compilationReport();

// Runtime, after performing the same registration.
$c->useCompiled($path);
```

Artifacts include PHP, InterMix, environment, definition, resolution
configuration, lifetime, tag, and compiled-recipe fingerprints. Incompatible
artifacts fail closed. Automatic class recipes pass conservative cache-time
eligibility checks; contextual, attributed, resource-configured, implicit-method,
and otherwise dynamic definitions remain on the normal resolver with an exact
reason in the compilation report. A later container configuration mutation
disables the active map until it is rebuilt. Ordinary closures and
`bindFactory()` definitions deliberately remain dynamic.

See the [compiled resolver guide](https://docs.infocyph.com/projects/InterMix/en/latest/di/compiled-resolvers.html).

### Closure Serialization

```php
use Infocyph\InterMix\Serializer\ClosureSerializer;

$payload = ClosureSerializer::serialize(static fn (int $value): int => $value * 2);
$closure = ClosureSerializer::unserialize($payload);

$signed = ClosureSerializer::signed($_ENV['APP_KEY']);
$signedPayload = $signed->serialize(static fn (): string => 'queued work');
$signedClosure = $signed->unserialize($signedPayload);
```

Ordinary PHP values use native PHP facilities. Resources remain the application's
responsibility. Signing is instance-scoped and adds no work to normal invocation.

## Testing

```bash
composer install
composer ic:tests
```


## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/Intermix/security/advisories/new).

Intermix is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.


---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Intermix/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/Intermix/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/Intermix/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/Intermix/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/Intermix/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/Intermix/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/Intermix/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
