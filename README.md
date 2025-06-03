# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/build.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/build.yml)
[![Documentation Status](https://readthedocs.org/projects/intermix/badge/?version=latest)](https://intermix.readthedocs.io)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)

`InterMix` is a lightweight and versatile PHP toolkit focused on class-oriented programming. It provides frequently-needed utilities like dependency injection, memoization, class macro support and more — all optimized for speed, simplicity and scalability.

---

## 🚀 Key Features

- **Dependency Injection (Container)** — PSR-11 compatible, extensible container.
- **Caching** — PSR-6 & PSR-16 compatible, extensible cache library.
- **Class Barrier (Fence)** — Protects class lifecycle via single-entry enforcement.
- **Class Macros (MacroMix)** — Dynamically attach behavior to classes.
- **Memoization** — Instance-based caching via `MemoizeTrait`.
- **Global Helpers** — Intuitive tools like `pipe()`, `retry()`, `measure()` and `once()` for clean and expressive code.

---

## 📦 Installation

```bash
composer require infocyph/intermix
````

Supported PHP versions:

| InterMix Version | PHP Version        |
| ---------------- | ------------------ |
| 2.x.x and above  | 8.2 or newer       |
| 1.x.x            | 8.0–8.1 compatible |

---

## 🧪 Quick Examples

### Dependency Injection

```php
use Infocyph\InterMix\Container;

$container = new Container();
$service = $container->get('my_service');
// Use the service...
```

### Class Macros

```php
MacroTestClass::mix(new class {
    public function greet($name) {
        return "Hello, $name!";
    }
});

echo (new MacroTestClass)->greet('Alice'); // Hello, Alice!
```

### Per-Call-Site Memoization with `once()`

```php
use function Infocyph\InterMix\Remix\once;

$value1 = once(fn() => rand(1, 999)); // Runs and caches
$value2 = once(fn() => rand(1, 999)); // Returns cached result from same file:line
```

---

## 📚 Documentation

Full documentation is hosted on **[Read the Docs](https://intermix.readthedocs.io)**.
You’ll find:

* 🧩 Module overviews
* 🧪 Code examples
* 📖 API references
* 📘 PDF/ePub downloads

View latest: [https://intermix.readthedocs.io](https://intermix.readthedocs.io)

---

## ✅ Testing

```bash
composer install
composer test
```

---

## 🤝 Contributing

Want to help? File issues, request features, or open pull requests here:
👉 [github.com/infocyph/InterMix/issues](https://github.com/infocyph/InterMix/issues)

---

## 🛡 License

This project is open-source under the **[MIT License](https://opensource.org/licenses/MIT)**.
