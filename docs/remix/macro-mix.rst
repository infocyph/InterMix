.. _remix.macro-mix:

=========================
MacroMix (Mixin Trait)
=========================

Have you ever wished your PHP class had a certain method—even though it’s
not defined in the class? `MacroMix` lets you **dynamically add methods
(macros) at runtime** to any class without requiring a base class. It works
purely via PHP’s `__call` / `__callStatic` magic under the hood.

Advantages
----------

- **Zero coupling** — You don’t need to extend a base class. Just `use MacroMix;`.
- **Method chaining** — If your macro returns `null`, it automatically returns
  `$this` so you can continue chaining.
- **Config or annotation loading** — Bulk‐load macros from arrays or
  `@Macro("name")` annotations.
- **Thread safety** (optional) — Enable a lock if you care about concurrency.

Basic usage
===========

Include the trait in any class:

.. code-block:: php

   namespace App;

   use Infocyph\\InterMix\\Remix\\MacroMix;

   class House
   {
       use MacroMix;

       // Optional: enable thread-safe macro registration
       public const ENABLE_LOCK = true;

       protected string $color = 'gold';
   }
```

### Registering a macro

```php
public static function macro(string $name, callable|object $macro): void
```

* **\$name**: method name you want to add.
* **\$macro**: a callable or an object.  If it’s a closure, it will be wrapped
  so that if it returns `null`, `$this` is returned instead (method chaining
  enabled).

**Example:**

```php
House::macro('fill', function (string $color) {
    $this->color = $color;
    return $this;  // ensure chaining
});

$house = new House();
$house->fill('blue')->fill('green');
echo $house->color;  // "green"
```

### Checking & removing

```php
public static function hasMacro(string $name): bool
public static function removeMacro(string $name): void
```

**Example:**

```php
House::macro('sayHello', fn() => 'Hello');
echo House::hasMacro('sayHello'); // true

House::removeMacro('sayHello');
echo House::hasMacro('sayHello'); // false
```

### Calling macros

Any time you call an undefined method (static or instance), `MacroMix` checks
its internal registry:

* If the macro exists, it invokes it—binding `$this` if needed.
* If the macro returns `null`, the trait yields `$this` (or the class name, if
  invoked statically).
* If the macro is not found, it throws:

  ```
  Exception: Method ClassName::missingMacro does not exist.
  ```

**Example:**

```php
House::macro('floor', fn() => 'I am the floor');
echo House::floor();  // “I am the floor”
```

### Loading from configuration

```php
public static function loadMacrosFromConfig(array $config): void
```

* **\$config**: `['name' => fn(...), 'otherName' => fn(...)]`
* Before registering, this method acquires a lock if `ENABLE_LOCK` is true.

**Example:**

```php
$config = [
    'toUpper' => fn($s) => strtoupper($s),
    'reverse' => fn($s) => strrev($s),
];
House::loadMacrosFromConfig($config);

$h = new House();
echo $h->toUpper('gtk');   // “GTK”
echo $h->reverse('php');    // “php” reversed = “php”
```

### Loading from annotations

```php
public static function loadMacrosFromAnnotations(string|object $class): void
```

* Scans PHPDoc of all public methods for `@Macro("name")`.
* Registers each matching method as a macro under `"name"`.

**Example:**

```php
class MyMixin
{
    /**
     * @Macro("shout")
     */
    public function shout(string $text): string
    {
        return strtoupper($text) . '!';
    }
}

// Load it:
House::loadMacrosFromAnnotations(MyMixin::class);

$h = new House();
echo $h->shout('hello');  // “HELLO!”
```

### Retrieving all macros

```php
public static function getMacros(): array
```

* Returns `['macroName' => callable, ...]` of everything registered.

**Example:**

```php
House::macro('one', fn()=>1);
House::macro('two', fn()=>2);

$macros = House::getMacros();
// $macros = ['one' => <callable>, 'two' => <callable>];
```

### Mixing in an entire class/object

```php
public static function mix(object|string $mixin, bool $replace = true): void
```

* **\$mixin** can be an object or a fully-qualified class name string.
* It will reflect on **all public+protected methods** of that object (or a new
  instance if you passed a class name).
* If a method is static in `$mixin`, the macro wraps a static invocation;
  otherwise it wraps an invocation bound to the passed instance.
* Set `$replace = false` if you want to skip any macro names that already exist.

**Example:**

```php
$mixin = new class {
    public function greet(string $name): string {
        return "Hello, $name!";
    }
    protected function whisper(string $msg): string {
        return "psst... $msg";
    }
};

House::mix($mixin);
$h = new House();
echo $h->greet('World');  // “Hello, World!”
echo $h->whisper('John'); // “psst... John”
```

### Error if undefined macro

If you call `$house->nonexistent()`, you get:

```text
Exception: Method App\House::nonexistent does not exist.
```

### Thread safety (optional)

By default, no locking is done.  If you set

```php
class House {
    use MacroMix;
    public const ENABLE_LOCK = true;
}
```

then:

* All register/write operations (`macro()`, `removeMacro()`, `loadMacrosFromConfig()`)
  acquire an exclusive flock on the MacroMix source file, preventing
  concurrent writes from racing.
* Read-only checks (`hasMacro()`, `getMacros()`, calls to existing macros) bypass locking.
