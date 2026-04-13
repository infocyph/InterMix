# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/build.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/build.yml)
[![Documentation](https://img.shields.io/badge/Documentation-InterMix-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/intermix/)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)

`InterMix` is a modern, lightweight PHP toolkit for developers who value class-oriented design, clean architecture, and fast execution. It combines dependency injection, serialization, macro-style extensibility, and helper utilities with minimal config and maximum control.

---

## 🚀 Key Features

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

---

## 📦 Installation

```bash
composer require infocyph/intermix
```

Supported PHP versions:

| InterMix Version | PHP Version        |
| ---------------- | ------------------ |
| 2.x.x and above  | 8.3 or newer       |
| 1.x.x            | 8.0-8.2 compatible |

---

## ⚡ Quick Examples

### 🧱 Dependency Injection

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
    $svc()->handle();
}
```

See full container guide at:
📖 [https://docs.infocyph.com/projects/intermix/di/overview.html](https://docs.infocyph.com/projects/intermix/di/overview.html)

---

### 🧬 Dynamic Macros

```php
MacroTest::mix(new class {
    public function hello($name) {
        return "Hey, $name!";
    }
});

echo (new MacroTest)->hello('Ali'); // Hey, Ali!
```

---

### 🧠 Definition Cache (Injectable)

```php
use Psr\Cache\CacheItemPoolInterface;

$pool = /* any PSR-6 pool, e.g. from infocyph/cachelayer */;
$c->definitions()->enableDefinitionCache($pool);
```

---

## 📚 Documentation

Full documentation available at:

🔗 [https://docs.infocyph.com/projects/intermix/](https://docs.infocyph.com/projects/intermix/)

Includes:

* ✅ Getting Started & Quickstart
* 📦 DI Container Guide (bindings, scopes, attributes, lifetimes)
* 🧩 Modules: DI, Serializer, Remix, Fence, MacroMix
* 🧪 Testing & Caching Tips
* 📘 PDF/ePub formats

---

## ✅ Testing

```bash
composer install
composer test
```

---

## 🤝 Contributing

Got ideas or improvements? Join us!

📂 [Open issues](https://github.com/infocyph/InterMix/issues)
📬 Submit a PR — we welcome quality contributions

---

## 🛡 License

MIT Licensed — use it freely, modify it openly.

🔗 [opensource.org/licenses/MIT](https://opensource.org/licenses/MIT)
