# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)
[![Documentation](https://img.shields.io/badge/Documentation-InterMix-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/intermix/)

`InterMix` is a modern, lightweight PHP toolkit for developers who value class-oriented design, clean architecture, and fast execution. It combines dependency injection, serialization, macro-style extensibility, and helper utilities with minimal config and maximum control.

> Global helper functions are optional: core APIs are namespaced and helper loading is opt-in.

## Key Features

- **Dependency Injection (DI)** — PSR-11 compliant container with:
  - attribute-based injection
  - scoped lifetimes
  - lazy loading
  - environment-specific overrides
  - debug tracing & definition-cache integration via assignable PSR-6 pool
- **Serializer** — Closure-aware value serialization and resource handlers
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

Current InterMix releases require PHP 8.3 or newer, as declared by `composer.json`.

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

$pool = /* any PSR-6 pool, e.g. from infocyph/cachelayer */;
$c->definitions()->enableDefinitionCache($pool, cacheRuntimeObjects: false);
```

### Compiled Resolvers

```php
$path = __DIR__ . '/var/intermix.compiled.php';

// build-time: generate compiled resolver map
$c->compileTo($path);

// runtime: activate compiled resolver map explicitly
$c->useCompiled($path);

// optional one-step build + activate
$c->compileTo($path, load: true);
```

### Signed Serialization

```php
$signed = \Infocyph\InterMix\Serializer\ValueSerializer::signed($_ENV['APP_KEY']);
$token = $signed->encode(['user_id' => 1]);
$payload = $signed->decode($token);
```

## Testing

```bash
composer install
composer ic:tests
```


## Security

Protected by [PHPForge](https://github.com/infocyph/PHPForge) — an automated quality and security gate for PHP projects.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/intermix/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a> •
  <a href="https://github.com/infocyph/InterMix/issues">Report | Request | Suggest</a>
</div>
