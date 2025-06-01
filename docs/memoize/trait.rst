.. _memoize.trait:

==================
MemoizeTrait
==================

Sometimes you want per‐instance memoization, but you do not need or want the
global `Memoizer`.  In that case, you can use `Infocyph\InterMix\Memoize\MemoizeTrait`
in any class.  It stores results in a **private array** on the object itself, so
when the object is destroyed, its cache goes away.

Trait API
---------

.. py:trait:: MemoizeTrait

   **Protected methods:**

   .. py:method:: mixed memoize(string $key, callable $producer)

      - Check if `$this->__memo[$key]` already exists.
        - If yes, return it (cache hit).
        - If no, invoke `$producer()`, store the result under `$key`, and return it (cache miss).

      - `$key` is typically something like `__METHOD__` (a unique string).
      - `$producer` is a zero‐argument closure that returns whatever you want cached.

   .. py:method:: void memoizeClear(?string $key = null)

      - If `$key` is `null`, removes **all** memoized entries (`$this->__memo = []`).
      - If `$key` is provided, only unsets `$this->__memo[$key]` if it exists.

Usage Example
-------------

.. code-block:: php

   namespace App;

   use Infocyph\\InterMix\\Memoize\\MemoizeTrait;

   class Repo
   {
       use MemoizeTrait;

       public int $counter = 0;

       public function fetchData(): int
       {
           // Only increments $counter on the very first call; subsequent calls return the cached int.
           return $this->memoize(__METHOD__, fn() => ++$this->counter);
       }

       public function clearCache(): void
       {
           // Clear all cached entries for this object
           $this->memoizeClear();
       }
   }

Example Scenario
~~~~~~~~~~~~~~~~

.. code-block:: php

   $repo = new Repo();

   // First call: __METHOD__ key does not exist, so $counter becomes 1, returns 1.
   $first  = $repo->fetchData(); // $first === 1

   // Second call: __METHOD__ key already has a cached value (1), so returns 1 again.
   $second = $repo->fetchData(); // $second === 1

   // Under the hood, $repo->__memo = [ 'App\Repo::fetchData' => 1 ].

   // If you clear, the next call recomputes:
   $repo->clearCache();
   $third = $repo->fetchData();   // Now increments $counter to 2, returns 2.

Key Points
~~~~~~~~~~

- The cache is strictly **per‐object**.  Two different `Repo` instances have separate caches.
- The cached value is stored in `$this->__memo[$key]`.  You choose a unique `$key` (often `__METHOD__`).
- If you want to clear one entry, call `memoizeClear(__METHOD__)`.  Otherwise pass `null` to wipe all.
- No external dependencies—uses only a plain PHP array on `$this`.
