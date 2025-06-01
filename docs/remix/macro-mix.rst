.. _remix.macro-mix:

=========================
MacroMix (Mixin Trait)
=========================

Have you ever wished your PHP class had a certain method—even though it’s
not defined in the class? ``MacroMix`` lets you **dynamically add methods
(macros) at runtime** to any class without requiring a base class. It works
purely via PHP’s ``__call`` / ``__callStatic`` magic under the hood.

Advantages
==========

- **Zero coupling** — You don’t need to extend a base class. Just ``use MacroMix;``.
- **Method chaining** — If your macro returns ``null``, it automatically returns
  ``$this`` so you can continue chaining.
- **Config or annotation loading** — Bulk-load macros from arrays or
  ``@Macro("name")`` annotations.
- **Thread safety** (optional) — Enable a lock if you care about concurrency.

Basic Usage
===========

Include the trait in any class:

.. code-block:: php

   namespace App;

   use Infocyph\InterMix\Remix\MacroMix;

   class House
   {
       use MacroMix;

       // Optional: enable thread-safe macro registration
       public const ENABLE_LOCK = true;

       protected string $color = 'gold';
   }

Registering a Macro
===================

.. code-block:: php

   public static function macro(string $name, callable|object $macro): void

- ``$name``: method name you want to add.
- ``$macro``: a callable or object. Closures are wrapped so that if they return
  ``null``, ``$this`` is returned instead for chaining.

**Example:**

.. code-block:: php

   House::macro('fill', function (string $color) {
       $this->color = $color;
       return $this;
   });

   $house = new House();
   $house->fill('blue')->fill('green');
   echo $house->color;  // "green"

Checking and Removing Macros
============================

.. code-block:: php

   public static function hasMacro(string $name): bool
   public static function removeMacro(string $name): void

**Example:**

.. code-block:: php

   House::macro('sayHello', fn() => 'Hello');
   echo House::hasMacro('sayHello'); // true

   House::removeMacro('sayHello');
   echo House::hasMacro('sayHello'); // false

Calling Macros
==============

Any time you call an undefined method (static or instance), ``MacroMix`` checks
its internal registry:

- If the macro exists, it invokes it—binding ``$this`` if needed.
- If the macro returns ``null``, the trait yields ``$this`` (or the class name,
  if invoked statically).
- If the macro is not found, it throws:

.. code-block:: text

   Exception: Method ClassName::missingMacro does not exist.

**Example:**

.. code-block:: php

   House::macro('floor', fn() => 'I am the floor');
   echo House::floor();  // “I am the floor”

Loading from Configuration
==========================

.. code-block:: php

   public static function loadMacrosFromConfig(array $config): void

- ``$config``: associative array of ``['name' => callable, ...]``
- If ``ENABLE_LOCK`` is set, a lock is acquired during registration.

**Example:**

.. code-block:: php

   $config = [
       'toUpper' => fn($s) => strtoupper($s),
       'reverse' => fn($s) => strrev($s),
   ];
   House::loadMacrosFromConfig($config);

   $h = new House();
   echo $h->toUpper('gtk');   // “GTK”
   echo $h->reverse('php');   // “php”

Loading from Annotations
=========================

.. code-block:: php

   public static function loadMacrosFromAnnotations(string|object $class): void

- Scans public method PHPDoc for ``@Macro("name")``.
- Registers the method under the given name.

**Example:**

.. code-block:: php

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

   House::loadMacrosFromAnnotations(MyMixin::class);

   $h = new House();
   echo $h->shout('hello');  // “HELLO!”

Retrieving All Macros
======================

.. code-block:: php

   public static function getMacros(): array

Returns a list of all registered macros in the form:

.. code-block:: php

   ['macroName' => callable, ...]

**Example:**

.. code-block:: php

   House::macro('one', fn() => 1);
   House::macro('two', fn() => 2);

   $macros = House::getMacros();
   // ['one' => callable, 'two' => callable]

Mixing in an Entire Class or Object
===================================

.. code-block:: php

   public static function mix(object|string $mixin, bool $replace = true): void

- ``$mixin``: either an object instance or class name.
- Public and protected methods are reflected.
- Static methods wrap static invocation; non-static methods bind to instance.
- ``$replace = false`` skips existing macro names.

**Example:**

.. code-block:: php

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

Error on Undefined Macro
========================

If you call a macro that doesn't exist:

.. code-block:: php

   $house->nonexistent();

Results in:

.. code-block:: text

   Exception: Method App\House::nonexistent does not exist.

Thread Safety
=============

If you define the constant:

.. code-block:: php

   class House {
       use MacroMix;
       public const ENABLE_LOCK = true;
   }

Then:

- Write operations (`macro()`, `removeMacro()`, `loadMacrosFromConfig()`) acquire an exclusive file lock on the trait source.
- Read operations (e.g. `hasMacro()`, `getMacros()`, macro calls) skip locking.
