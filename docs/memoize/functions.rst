.. _memoize.functions:

========================
Global Memoization API
========================

Two simple helpers that both return a shared singleton `Memoizer` and
cache callables for the lifetime of the PHP process.

Functions
---------

`memoize(callable $fn = null, array $params = []): mixed`
  Cache a callable’s result **globally** (one slot per unique signature).

  - If `$fn === null`, returns the `Memoizer` instance.

  **Example**:

  .. code-block:: php

     use function Infocyph\InterMix\Memoize\memoize;

     // first call: cache miss
     $one = memoize(fn($x) => $x * 2, [3]); // 6

     // second call: cache hit
     $two = memoize(fn($x) => $x * 2, [3]); // 6

     // inspect stats
     $stats = memoize()->stats();
     // $stats === ['hits'=>1, 'misses'=>1, 'total'=>2]


`remember(object $obj = null, callable $fn = null, array $params = []): mixed`
  Cache a callable’s result **per‐instance** using a `WeakMap`.

  - If `$obj === null`, returns the `Memoizer` instance.
  - If `$obj` provided but `$fn === null`, an `InvalidArgumentException` is thrown.

  **Example**:

  .. code-block:: php

     use function Infocyph\InterMix\Memoize\remember;

     class UserData { /* … */ }

     $user = new UserData();

     // first call: miss
     $profile1 = remember($user, fn() => loadProfileFromDb($user));

     // second call: hit
     $profile2 = remember($user, fn() => loadProfileFromDb($user));

     // clearing stats and caches:
     memoize()->flush();
