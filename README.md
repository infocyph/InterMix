# InterMix

[![Security & Standards](https://github.com/infocyph/InterMix/actions/workflows/build.yml/badge.svg)](https://github.com/infocyph/InterMix/actions/workflows/build.yml)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/357a4bc6311c4dc892b67b89970fb096)](https://app.codacy.com/gh/infocyph/InterMix/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Documentation Status](https://readthedocs.org/projects/intermix/badge/?version=latest)](https://intermix.readthedocs.io)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/intermix?color=green&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fintermix)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/intermix)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/intermix/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/intermix)
![Visitors](https://visitor-badge.laobi.icu/badge?page_id=infocyph.com)

`InterMix` is a lightweight & versatile PHP library designed to provide commonly needed class-oriented tools with simplicity and efficiency. Whether you are managing dependencies or enhancing classes with macros, InterMix has you covered.

---

## Key Features

- **Dependency Injector (Container)**: Fully PSR-11 compliant for seamless integration.
- **Class Initialization Barrier (Fence)**: Ensures safe and controlled initialization of classes.
- **Class Macro (MacroMix)**: Extend classes dynamically with powerful macros.
- **Memoization**: Improve performance by caching function results.

---

## Prerequisites

- PHP 8.2 or higher

| Library Version | PHP Version       |
|-----------------|-------------------|
| 2.x.x           | 8.2.x or Higher   |
| 1.x.x           | 8.x.x             |

---

## Installation

Install InterMix using Composer:

```bash
composer require infocyph/intermix
```

---

## Getting Started

Here's how you can quickly get started with InterMix:

### Dependency Injection Example

```php
use Infocyph\InterMix\Container;

$container = new Container();

// Resolve and use the service
$exampleService = $container->get('example_service');
$exampleService->performAction();
```

### Using Class Macros

```php
$mixin = new class
    {
        public function greet($name)
        {
            return "Hello, $name!";
        }

        protected function whisper($message)
        {
            return "psst... $message";
        }
    };

    MacroTestClass::mix($mixin);
$object = new MacroTestClass;
echo $object->greet('World'); // Hello, World!
echo $object->whisper('John'); // psst... John
```

---

## Documentation

Comprehensive documentation is available on [Read the Docs](https://intermix.readthedocs.io). It covers everything from installation to advanced use cases.

---

## Testing

To ensure the library is functioning as expected, you can run the test suite. Make sure you have the necessary dependencies installed:

```bash
composer install
composer test
```

---

## Contributing

We welcome contributions! If you encounter bugs, have feature requests, or want to help improve the library, please [create an issue](https://github.com/infocyph/InterMix/issues).

---

## Support

Need help? Open an issue or reach out through the GitHub repository. We're here to help!

---

## License

InterMix is licensed under the [MIT License](https://opensource.org/licenses/MIT). Feel free to use and modify it as needed.
