.. _fence:

==========================================
Fence (Class Initialization Barrier)
==========================================

The ``Fence`` package provides a single core trait (``Fence``)
and three lightweight wrappers (``Single``, ``Multi``, ``Limit``) that let you:

- Control exactly how many objects of a class may exist.
- Choose whether instances are “keyed” by string or always a singleton.
- Enforce optional PHP‐extension/class requirements at startup.
- Inspect or reset active instances.

Everything lives in one place—no base classes, just include the trait you need.

Key Concepts
------------

**Unified base (Fence)**

Implements all logic for requirement checks, keyed vs. singleton behavior,
and instance‐count limits. You do **not** use ``Fence`` directly; one of the three
wrapper traits sets two class‐constants and the core logic runs on every
``::instance()`` call.

**Singleton (Single)**

Classes define ``FENCE_KEYED = true``. Only one instance can exist.
Key is optional; defaults to ``'__single'``.

**Multiton (Multi)**

Classes define ``FENCE_KEYED = false``. Multiple instances allowed, keyed by first argument.
Key is required; defaults to ``'default'``.

**Limited Multiton (Limit)**

Extends Multi with configurable instance limits.
Classes define ``FENCE_LIMIT = <int>``.
``setLimit(int)`` changes the limit at runtime.

**Constants Available**

- ``FENCE_KEYED`` – Whether class uses keyed instances (bool)
- ``FENCE_LIMIT`` – Maximum instances allowed (int)

**Requirement Checking**

``::instance()`` accepts optional constraints array with ``extensions`` and/or
``classes``. If any extension or class is missing, a ``RequirementException`` is thrown
before any instance is created.

**New Features Added**

- **Instance inspection** – ``hasInstance()``, ``countInstances()``, ``getInstances()``, ``getKeys()``
- **Cache management** – ``clearInstances()`` for testing
- **Runtime limit override** – ``setLimit()`` for dynamic configuration
- **Enhanced error handling** – Better exception messages and validations new object

**Singleton (Single)**

::

    namespace App;

    use Infocyph\InterMix\Fence\Single;

    class Config
    {
        use Single;
    }

    // Usage:
    $cfgA = Config::instance();       // new Config
    $cfgB = Config::instance();       // same object as $cfgA
    Config::hasInstance();            // true
    Config::countInstances();         // 1
    Config::getKeys();                // ["__single"]
    Config::clearInstances();         // resets so next instance() yields new object

**Multiton (Multi)**

::

    namespace App;

    use Infocyph\InterMix\Fence\Multi;

    class Connection
    {
        use Multi;
    }

    // Usage:
    $conn1 = Connection::instance("db1");
    $conn2 = Connection::instance("db2");
    $conn3 = Connection::instance("db1");  // returns same as $conn1
    Connection::countInstances();          // 2
    Connection::getKeys();                 // ["db1", "db2"]
    Connection::hasInstance("db3");        // false
    Connection::clearInstances();

**Limited Multiton (Limit)**

::

    namespace App;

    use Infocyph\InterMix\Fence\Limit;

    class ReportCache
    {
        use Limit;
    }

    // By default, Limit uses 2. Override at runtime:
    ReportCache::instance("rA");
    ReportCache::instance("rB");
    ReportCache::instance("rC");           // throws LimitExceededException if >2 and setLimit not called
    ReportCache::setLimit(3);              // Override to accept 3
    ReportCache::instance("rC");           // now allowed

**Requirement Checking**

``::instance()`` accepts an optional constraints array with ``extensions`` and/or
``classes``. If any extension or class is missing, a ``RequirementException`` is thrown
before any instance is created.

**Limit Enforcement**

Attempting to create more instances than the configured limit throws
``LimitExceededException``.

**Instance Inspection & Management**

The wrapper traits supply static helpers:

- ``hasInstance(?string $key = "default")`` → bool
- ``countInstances()`` → int
- ``getInstances()`` → array(key → instance)
- ``getKeys()`` → array of slots/keys
- ``clearInstances()`` → resets to empty
- ``setLimit(int $n)`` (only on ``Limit``)

Exceptions
----------

- **RequirementException**

  Raised if provided constraints refer to missing extensions or classes. The
  message lists exactly which items were not found.

- **LimitExceededException**

  Raised if you attempt to create a new instance when the count of existing
  instances has reached the configured limit.

- **InvalidArgumentException**

  Raised by ``setLimit()`` if you pass an integer less than 1.

Usage Examples
--------------

Defining classes::

    namespace App;

    use Infocyph\InterMix\Fence\Single;
    use Infocyph\InterMix\Fence\Multi;
    use Infocyph\InterMix\Fence\Limit;

    class Config {
        use Single;
    }

    class Connection {
        use Multi;
    }

    class ReportCache {
        use Limit;
    }

Creating and inspecting instances::

    // SINGLETON:
    $cfgA = Config::instance();       // new Config
    $cfgB = Config::instance();       // same object as $cfgA
    Config::hasInstance();            // true
    Config::countInstances();         // 1
    Config::getKeys();                // ["__single"]
    Config::clearInstances();         // resets so next instance() is new

    // MULTITON:
    $conn1 = Connection::instance("db1");
    $conn2 = Connection::instance("db2");
    $conn3 = Connection::instance("db1");  // returns same as $conn1
    Connection::countInstances();          // 2
    Connection::getKeys();                 // ["db1", "db2"]
    Connection::hasInstance("db3");        // false
    Connection::clearInstances();

    // LIMITED MULTITON:
    ReportCache::instance("rA");
    ReportCache::instance("rB");
    // Next line throws LimitExceededException (limit=2 by default):
    ReportCache::instance("rC");

    // Change limit to 3:
    ReportCache::setLimit(3);
    ReportCache::instance("rC");           // now allowed
    ReportCache::countInstances();         // 3

Applying requirements::

    // Suppose you need 'curl' and 'mbstring' extensions and 'PDO' class:
    try {
        $db = Connection::instance("main", [
            'extensions' => ['curl','mbstring'],
            'classes'    => ['PDO'],
        ]);
    } catch (RequirementException $e) {
        echo $e->getMessage();
    }
    // If any extension/class is missing → RequirementException thrown earlier.

Best Practices
--------------

* **Always call ``::instance()``** instead of ``new``.
* If your class must remain a singleton, use ``Single``.
* If you need per‐key instances, use ``Multi``.
* If you want to cap how many objects can coexist, use ``Limit``.
* To enforce startup requirements, pass a constraints array to ``::instance()`` and catch ``RequirementException``.
