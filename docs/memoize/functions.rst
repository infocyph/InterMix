.. _memoize.functions:

========================
Global Memoization API
========================

Memoization is the process of caching the results of expensive function calls so that
subsequent calls with the same arguments return immediately from cache. In this section,
we expose **two** global helper functions—``memoize()`` and ``remember()``—that rely on a
shared singleton ``Memoizer``. The cache lives for the duration of the PHP process (or until
you explicitly clear it).

These functions are declared in ``Infocyph\InterMix\Memoize\functions.php`` and rely on
the ``Infocyph\InterMix\Memoize\Memoizer`` class under the hood. They throw
``InvalidArgumentException`` when misused.

Functions
---------

.. php:function:: mixed memoize(?callable $fn = null, array $params = []): mixed

   **Behavior**

   1. If called with **no** arguments, returns the shared ``Memoizer`` instance.
   2. If ``$fn`` is provided and not ``null``, it computes a unique **signature** for that callable
      (via ``ReflectionResource::getSignature()``) and either:

      - returns a cached result (cache hit), or
      - invokes ``$fn(...$params)``, stores the return value in the process‐local cache (cache miss),
        and returns it.

   **Signature generation**

   Every callable (closure, function name, object method, etc.) is assigned a deterministic string
   signature by reflecting on its code/location. That signature is used as the cache key.

   **Return value**

   - When called with no arguments: returns ``Memoizer::instance()``.
   - When ``$fn`` is provided: returns the callable’s return value (either cached or newly computed).

   **Exceptions**

   - Throws ``ReflectionException`` if introspecting the callable fails.

   **Example:**

   .. code-block:: php

      use function Infocyph\\InterMix\\Memoize\\memoize;

      // 1) Retrieve the Memoizer instance to inspect stats:
      $m = memoize();
      // $m instanceof Infocyph\\InterMix\\Memoize\\Memoizer

      // 2) First time: computes and caches
      $valueA = memoize(fn(int $x) => $x + 1, [4]);
      // $valueA === 5

      // 3) Second time with same callable and params: cached
      $valueB = memoize(fn(int $x) => $x + 1, [4]);
      // $valueB === 5

      // Inspect hit/miss stats:
      $stats = memoize()->stats();
      // e.g. ['hits' => 1, 'misses' => 1, 'total' => 2]

.. php:function:: mixed remember(?object $obj = null, ?callable $fn = null, array $params = []): mixed

   **Behavior**

   1. If called with **no** arguments, returns the same shared ``Memoizer`` instance.
   2. If ``$obj`` is provided but ``$fn`` is ``null``, throws ``InvalidArgumentException``.
   3. If both ``$obj`` and ``$fn`` are provided:

      - Computes a signature for ``$fn`` (using ``ReflectionResource::getSignature()``).
      - Looks up the cache bucket for that particular object instance (stored in a ``WeakMap``).
      - If a cached value exists for that signature under ``$obj``, returns it (hit).
      - Otherwise, invokes ``$fn(...$params)``, stores the result in this object’s bucket, and returns it (miss).

   **Return value**

   - When called with no arguments: returns ``Memoizer::instance()``.
   - When ``$obj`` and ``$fn`` are provided: returns the callable’s return value, cached per‐object.

   **Exceptions**

   - Throws ``InvalidArgumentException`` if ``$obj`` is non‐null and ``$fn`` is ``null``.
   - Throws ``ReflectionException`` if reflecting on ``$fn`` fails.

   **Example:**

   .. code-block:: php

      use function Infocyph\\InterMix\\Memoize\\remember;

      class UserData { /* … */ }

      $user = new UserData();

      // First call: miss, executes closure and caches it under $user
      $profile1 = remember($user, fn() => loadProfileFromDb($user));

      // Second call with same object, same callable: cache hit
      $profile2 = remember($user, fn() => loadProfileFromDb($user));

      // $profile1 and $profile2 are identical, and loadProfileFromDb() ran only once.

      // If you call remember() on a different object, closure runs again for that object.

   **Clearing the global cache (both static and per‐object buckets):**

   .. code-block:: php

      // Flush everything:
      memoize()->flush();

      // After this, every new memoize()/remember() call will be a cache miss.
