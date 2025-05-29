.. _fence:

==========================================
Fence (Class Initialization Barrier)
==========================================

The `Infocyph\InterMix\Fence` package delivers a **single** core trait,
`Fence`, plus three thin wrappers—`Single`, `Multi`, and `Limit`—to
give you fine-grained control over how classes instantiate:

Key Features
------------

- **Unified base (`Fence`)**
  All of the logic—requirement checks, keyed vs. singleton behaviour,
  and instance-count limits—lives in one place.
- **Singleton (`Single`)**
  `$keyed = false; $limit = 1` → exactly one instance ever.
- **Multiton (`Multi`)**
  `$keyed = true;  $limit = ∞` → keyed instances, unlimited count.
- **Limited Multiton (`Limit`)**
  `$keyed = true;  $limit = configurable` → keyed instances, bounded count.
- **Requirement Checking**
  Throw `RequirementException` if required PHP extensions or classes
  are missing.
- **Limit Enforcement**
  Throw `LimitExceededException` when you exceed the `$limit`.
- **Instance Inspection & Management**
  Methods like `hasInstance()`, `countInstances()`, `getInstances()`,
  `getKeys()`, `clearInstances()`, and `setLimit()` let you inspect
  and control the pool.

Exceptions
----------

- **`RequirementException`** — missing extensions or classes
- **`LimitExceededException`** — tried to create too many instances
- **`InvalidArgumentException`** — bad arguments to `setLimit()`

Basic Usage
-----------

Define your classes using one of the three wrappers:

.. code-block:: php

   use Infocyph\InterMix\Fence\Single;
   use Infocyph\InterMix\Fence\Multi;
   use Infocyph\InterMix\Fence\Limit;

   class OnlyOne {
       use Single;
   }

   class Many {
       use Multi;
   }

   class Few {
       use Limit;
       // default limit is 2; call Few::setLimit(5) to change
   }

Initialization Methods
----------------------

Instead of `new`, call `::instance()`:

.. code-block:: php

   // Singleton: same object every time
   $a = OnlyOne::instance();
   $b = OnlyOne::instance();
   // $a === $b

   // Multiton: different per key
   $x = Many::instance('x');
   $y = Many::instance('y');
   // $x !== $y

   // Limited: up to N instances
   Few::setLimit(3);
   Few::instance('a');
   Few::instance('b');
   Few::instance('c');
   // Few::instance('d') would throw LimitExceededException

Applying Requirements
---------------------

You may pass an optional constraints array to `instance()`:

.. code-block:: php

   try:
       $obj = OnlyOne::instance(key: null, constraints: [
           'extensions' => ['curl','mbstring'],
           'classes'    => ['PDO','DateTime'],
       ]);
   catch (RequirementException $e):
       echo $e->getMessage();
   endtry

Instance Inspection & Management
--------------------------------

Use the following public APIs to inspect or mutate your instance pool:

.. code-block:: php

   // Has an instance for the given key?
   Few::hasInstance('a');           // true

   // How many instances exist?
   Few::countInstances();           // e.g. 3

   // Get the raw array of instances
   $all = Many::getInstances();

   // Get just the keys
   $keys = Many::getKeys();

   // Remove all instances
   Many::clearInstances();

   // Change the limit dynamically
   Few::setLimit(10);

   // Check if singleton already created
   OnlyOne::hasInstance();          // true/false

Conclusion
----------

By unifying your initialization logic into one core trait and three simple
configuration wrappers, **Fence** gives you:

- **Strict control** over how many objects can exist
- **Safe startup** via extension/class requirement checks
- **Easy introspection** of active instances

All that without any extra base classes—just pull in the trait you need
and use `::instance()` instead of `new`.
