.. _fence:

==========================================
Fence (Class Initialization Barrier)
==========================================

The **`Fence`** package provides a unified way to control
how your PHP classes instantiate, by centralizing requirement checks,
singleton/multiton behavior, and instance‐count limits into a single trait
(`Fence`) plus three lightweight adapters (`Single`, `Multi`, `Limit`).  You
never call `new` directly—instead, you use `::instance()` to get your object.

Key Features
------------

- **Unified core (`Fence` trait)**
  All logic for “keyed vs. singleton” behavior, requirement‐checking, and
  instance‐limit enforcement lives in one place.

- **Singleton (`Single` wrapper)**
```

use Infocyph\InterMix\Fence\Single;
class OnlyOne { use Single; }
// Only one instance ever:
\$a = OnlyOne::instance();
\$b = OnlyOne::instance();
// \$a === \$b

```

- **Multiton (`Multi` wrapper)**
```

use Infocyph\InterMix\Fence\Multi;
class Many { use Multi; }
// Keyed instances, unlimited count:
\$x = Many::instance('key1');
\$y = Many::instance('key2');
// \$x !== \$y

```

- **Limited Multiton (`Limit` wrapper)**
```

use Infocyph\InterMix\Fence\Limit;
class Few { use Limit; }
// By default, limit = 2 (can be changed via Few::setLimit()):
Few::instance('A');
Few::instance('B');
// Few::instance('C') throws LimitExceededException

````

- **Requirement Checking**
Pass an optional `['extensions'=>[…], 'classes'=>[…]]` array to `instance()`.
If any required PHP extension or class is missing, a `RequirementException` is thrown.

- **Limit Enforcement**
If you exceed the configured instance limit, a `LimitExceededException` is thrown.

- **Introspection & Management**
- `hasInstance($key = null)` — check if an instance exists for a given key
- `countInstances()` — how many instances are active
- `getInstances()` — raw array of `[key => instance, …]`
- `getKeys()` — list of active keys
- `clearInstances()` — reset/remove all instances
- `setLimit(int $n)` — override the limit at runtime (for `Limit` classes)

Exceptions
----------

- **`RequirementException`** – Thrown if a required extension or class is not available.
- **`LimitExceededException`** – Thrown if attempting to instantiate beyond the allowed limit.
- **`InvalidArgumentException`** – Thrown if `setLimit($n)` receives a value `< 1`.

Basic Usage
-----------

First, pick the wrapper trait that matches your desired behavior:

.. code-block:: php

 use Infocyph\InterMix\Fence\Single;
 use Infocyph\InterMix\Fence\Multi;
 use Infocyph\InterMix\Fence\Limit;

 // 1) Singleton (no keys, exactly one instance):
 class OnlyOne {
     use Single;
 }

 // 2) Multiton (keyed, unlimited instances):
 class Many {
     use Multi;
 }

 // 3) Limited Multiton (keyed, bounded by a limit):
 class Few {
     use Limit;
     // default limit is 2; override with Few::setLimit(…)
 }

Initialization
--------------

Instead of `new SomeClass()`, call `SomeClass::instance($key, $constraints)`:

- **Singleton** (`Single`): `$key` is ignored (always one slot).
```php
$a = OnlyOne::instance();
$b = OnlyOne::instance();
// $a === $b
````

* **Multiton** (`Multi`): `$key` can be any string; each distinct key gets its own instance.

  ```php
  $x = Many::instance('alpha');
  $y = Many::instance('beta');
  // $x !== $y
  // Re‐calling Many::instance('alpha') yields the same $x.
  ```

* **Limited Multiton** (`Limit`): Behaves like `Multi`, but enforces a maximum count.

  ```php
  // By default, FENCE_LIMIT = 2
  Few::instance('red');
  Few::instance('blue');
  // Few::instance('green') ⇒ throws LimitExceededException
  ```

## Applying Requirements

You can optionally supply an array of requirements:

.. code-block:: php

use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Exceptions\RequirementException;

class OnlyOne {
use Single;
}

try {
// Require 'curl' extension and PDO class before instantiating:
\$obj = OnlyOne::instance(
key: null,
constraints: \[
'extensions' => \['curl', 'mbstring'],
'classes'    => \['PDO', 'DateTime'],
]
);
} catch (RequirementException \$e) {
// e.g. "Requirements not met: Extensions not loaded: mbstring; Classes not found: PDO"
echo \$e->getMessage();
}

If any specified extension is not loaded, or any specified class is not declared,
`RequirementException` is thrown.  Passing `null` or omitting `$constraints` bypasses checks.

## Instance Introspection & Management

Once you have a class using one of the Fence traits, you can inspect or adjust
the instance pool at any time:

.. code-block:: php

// For a Limited class (Few), temporarily increase limit:
Few::setLimit(5);

// Check if a particular key exists:
if (Few::hasInstance('red')) {
// … do something
}

// How many total instances are active?
\$count = Few::countInstances();

// List all active instances (key ⇒ object)
\$all = Many::getInstances();

// Just their keys
\$keys = Many::getKeys();

// Remove all instances (useful in unit tests)
Many::clearInstances();

// For a Singleton:
OnlyOne::hasInstance();   // true/false
OnlyOne::clearInstances(); // after this, instance() will create a fresh object

Limit Override (for `Limit`-wrapped classes)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

By default, a class using `Limit` has:

* `public const FENCE_KEYED = true;`
* `public const FENCE_LIMIT = 2;`

You can call `setLimit($n)` to raise (or lower) the maximum number of instances:

.. code-block:: php

Few::setLimit(10);
Few::instance('A');
// … up to 10 distinct keys without exception.

## Exceptions in Detail

* **`LimitExceededException`** (`Infocyph\InterMix\Exceptions\LimitExceededException`)
  Thrown by `Fence::instance()` when `count(self::$instances) >= $limit` and you attempt
  to create another instance.  Error message:

  ```
  "Instance limit of {limit} exceeded for {ClassName}"
  ```

* **`RequirementException`** (`Infocyph\InterMix\Exceptions\RequirementException`)
  Thrown by `Fence::checkRequirements()` if any required extension or class is missing.
  Error message composition depends on which dependencies are absent, for example:

  ```
  "Requirements not met: Extensions not loaded: mbstring; Classes not found: NonExistentClass"
  ```

* **`InvalidArgumentException`**
  Thrown by `setLimit($n)` if `$n < 1`:

  ```
  "Limit must be at least 1"
  ```

## Complete Reference (Trait API)

.. code-block:: php

namespace Infocyph\InterMix\Fence;

trait Fence
{
/\*\* @var array\<string,object> \*/
private static array \$instances = \[];

```
   /** @var array<string,int> */
   private static array $classLimits = [];

   /** @var array|null */
   private static ?array $cachedExtensions = null;
   private static ?array $cachedClasses    = null;

   /**
    * Get or create an instance.
    *
    * @param string|null $key        Key for keyed classes, or null for singleton classes (converted to '__single').
    * @param array|null  $constraints ['extensions'=>string[], 'classes'=>string[]] to check before instantiation.
    * @return static
    * @throws RequirementException|LimitExceededException
    */
   final public static function instance(
       ?string $key = 'default',
       ?array  $constraints = null
   ): static { /* … */ }

   /**
    * Override the per‐class limit at runtime.
    *
    * @param int $n  Must be ≥ 1.
    * @return void
    * @throws InvalidArgumentException
    */
   final public static function setLimit(int $n): void { /* … */ }

   /**
    * Check if an instance exists for the given key (or for '__single' in singleton classes).
    *
    * @param string|null $key
    * @return bool
    */
   final public static function hasInstance(?string $key = 'default'): bool { /* … */ }

   /**
    * Return an associative array of [key=>instance, …].
    *
    * @return array<string, static>
    */
   final public static function getInstances(): array { /* … */ }

   /**
    * Return the list of keys under which instances are stored.
    *
    * @return string[]
    */
   final public static function getKeys(): array { /* … */ }

   /**
    * Remove all instances (reset to empty).  Useful in tests.
    *
    * @return void
    */
   final public static function clearInstances(): void { /* … */ }

   /**
    * Get the total number of instances currently stored.
    *
    * @return int
    */
   final public static function countInstances(): int { /* … */ }
```

}

trait Limit
{
use Fence;

```
   public const FENCE_KEYED = true;
   public const FENCE_LIMIT = 2;
```

}

trait Multi
{
use Fence;

```
   public const FENCE_KEYED = true;
   public const FENCE_LIMIT = PHP_INT_MAX;
```

}

trait Single
{
use Fence;

```
   public const FENCE_KEYED = false;
   public const FENCE_LIMIT = 1;
```

}

## Putting It All Together

1. **Choose the behavior you need**

   * `Single` – exactly one instance, ignore `$key`.
   * `Multi` – unlimited, keyed instances.
   * `Limit` – keyed instances, but enforce a finite limit (default 2).

2. **Write your class**

   ```php
   use Infocyph\InterMix\Fence\Limit;

   class CacheService {
       use Limit;
       // By default, up to 2 distinct CacheService::instance('A'), instance('B').
   }
   ```

3. **Instantiate (and enforce requirements)**

   ```php
   try {
       // Require the ‘json’ extension and PDO class before instantiating:
       $svc = CacheService::instance('main', ['extensions'=>['json'], 'classes'=>['PDO']]);
   } catch (\Infocyph\InterMix\Exceptions\RequirementException $e) {
       // Handle missing dependencies…
   } catch (\Infocyph\InterMix\Exceptions\LimitExceededException $e) {
       // Too many instances…
   }
   ```

4. **Inspect & adjust**

   ```php
   // Check if 'main' already exists:
   if (CacheService::hasInstance('main')) { … }

   // How many active instances?
   $count = CacheService::countInstances();

   // Get all instances:
   $all = CacheService::getInstances(); // [ 'main' => CacheService-object, … ]

   // Clear them (e.g. in tearDown of your unit tests):
   CacheService::clearInstances();

   // If you need to raise the limit from 2 to 5 at runtime:
   CacheService::setLimit(5);
   ```
