# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/build.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/build.yml)
[![Documentation Status](https://readthedocs.org/projects/intermix/badge/?version=latest)](https://intermix.readthedocs.io)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)

`InterMix` is a modern, lightweight PHP toolkit for developers who love class-oriented design, clean architecture, and fast execution. From dependency injection to dynamic macros, every utility is designed to **just work** — with minimal config and maximum control.

---

## 🚀 Key Features

- **Dependency Injection (DI)** — PSR-11 compliant container with:
  - attribute-based injection
  - scoped lifetimes
  - lazy loading
  - environment-specific overrides
  - debug tracing & cache support
- **Caching** — Dual PSR-6 & PSR-16 compatible cache engine
- **Fence** — Enforce singleton-style class safety
- **MacroMix** — Dynamically extend objects or classes with macros
- **Memoizer** — `once()` and `memoize()` helpers for deterministic caching
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
📖 [https://intermix.readthedocs.io/en/latest/di/overview.html](https://intermix.readthedocs.io/en/latest/di/overview.html)

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

### 🧠 `once()` Memoization

```php
$value = once(fn() => rand(1000, 9999)); // Only runs once per file+line
```

---

## 📚 Documentation

Full documentation available on **ReadTheDocs**:

🔗 [https://intermix.readthedocs.io](https://intermix.readthedocs.io)

Includes:

* ✅ Getting Started & Quickstart
* 📦 DI Container Guide (bindings, scopes, attributes, lifetimes)
* 🧩 Modules: Memoizer, Fence, MacroMix
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
