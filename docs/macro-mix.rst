.. _macro-mix:

====================
Macro Mix (Mixin)
====================

Have you ever wished that a PHP class had another method that you’d like to use? ``MacroMix`` makes this dream come true.
This trait allows you to dynamically add methods (macros) to a class at runtime, execute them as if they were natively defined,
and supports advanced features like:

- Method chaining for dynamically added methods.
- Structured macro definitions through configurations and annotations.
- Dynamic mixing of an entire class of methods into another.
- Thread-safe operations using an optional lock switcher.

.. caution::

    It uses ``__call`` & ``__callStatic`` magic methods. Be careful if your
    class already uses them, as it may result in conflicts.



Thread Safety and Locking
=========================

The ``MacroMix`` trait supports thread-safe operations for concurrent environments. By enabling the ``ENABLE_LOCK`` constant
in your class, all operations that modify shared state will be protected with a locking mechanism. If the constant is not defined
or set to ``false``, locking will be bypassed for better performance in non-concurrent environments.

To enable locking, define the ``ENABLE_LOCK`` constant in your class:

.. code:: php

   class House {
       use MacroMix;

       public const ENABLE_LOCK = true;
   }

Locking is selectively applied to write operations (e.g., adding or removing macros) to avoid unnecessary overhead for read operations.



Registering and Calling Macros
==============================

You can dynamically add methods (macros) to a class using the ``macro`` method. Macros can be defined as closures or callable objects.

.. code:: php

   class House {
       use MacroMix;

       protected $color = 'gold';
   }

Add a macro dynamically:

.. code:: php

   House::macro('fill', function ($value) {
       $this->color = $value;
       return $this; // Enable method chaining
   });

   $house = new House();
   $house->fill('blue')->fill('green'); // Method chaining supported
   echo $house->color; // green

.. tip::

    Dynamically added methods can return ``$this`` to enable method chaining.



Checking and Removing Macros
============================

You can check whether a macro is registered using ``hasMacro`` and remove it using ``removeMacro``:

.. code:: php

   House::macro('example', fn() => 'Example Macro');
   echo House::hasMacro('example'); // true

   House::removeMacro('example');
   echo House::hasMacro('example'); // false



Mixing Methods
==============

You can mix an entire object or class (with multiple methods) into the current class. Methods from the mixin are added dynamically
to the target class.

.. code:: php

   $mixin = new class {
       public function greet($name) {
           return "Hello, $name!";
       }

       protected function whisper($message) {
           return "psst... $message";
       }
   };

   House::mix($mixin);

   $house = new House();
   echo $house->greet('World'); // Hello, World!
   echo $house->whisper('John'); // psst... John



Loading Macros
==============

Macros can be loaded from a configuration array or annotations.

Loading from Configuration
---------------------------

Macros can be defined in a configuration array and loaded into the class:

.. code:: php

   $config = [
       'toUpperCase' => fn($value) => strtoupper($value),
       'reverse' => fn($value) => strrev($value),
   ];

   House::loadMacrosFromConfig($config);

   $house = new House();
   echo $house->toUpperCase('gold'); // GOLD
   echo $house->reverse('gold'); // dlog

Loading from Annotations
-------------------------

Macros can also be defined using PHPDoc annotations in a class or object:

.. code:: php

   class MyMixin {
       /**
        * @Macro("shout")
        */
       public function shout($value) {
           return strtoupper($value) . '!';
       }
   }

   House::loadMacrosFromAnnotations(MyMixin::class);

   $house = new House();
   echo $house->shout('hello'); // HELLO!

.. note::

    Macros registered through annotations must include the ``@Macro`` tag in their PHPDoc comments.



Retrieving All Macros
=====================

You can retrieve all registered macros using the ``getMacros`` method:

.. code:: php

   House::macro('macroOne', fn() => 'Macro 1');
   House::macro('macroTwo', fn() => 'Macro 2');

   print_r(House::getMacros());
   // Output:
   // [
   //     'macroOne' => callable,
   //     'macroTwo' => callable,
   // ]



Error Handling
==============

Calling an undefined macro will throw an exception:

.. code:: php

   $house = new House();
   echo $house->undefinedMacro(); // Throws an exception

   // Exception Message: Method House::undefinedMacro does not exist.



Advanced Notes
==============

1. **Thread Safety**:
   - Locking can be enabled by defining the ``ENABLE_LOCK`` constant as ``true``.
   - Write operations (e.g., ``macro`` and ``removeMacro``) are protected with locks to ensure thread safety.
   - Read operations (e.g., ``getMacros`` and ``hasMacro``) are not locked to improve performance.

2. **Backtracing**:
   If you're using IDEs or static analysis tools, they may not recognize dynamically added methods. In such cases, use the PHPDoc format:

   .. code:: php

      /** @var Namespace\ClassName $this */

3. **Method Chaining**:
   - Ensure dynamically added methods return the calling object (`$this`) where necessary.

4. **Conflict Resolution**:
   - If a macro with the same name already exists, it will be overwritten only if explicitly allowed in the method call.



``MacroMix`` provides powerful tools to dynamically extend your classes, making your code more flexible and reusable.
