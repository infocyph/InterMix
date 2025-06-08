.. _remix.helpers:

=========================
Global Helper Functions
=========================

In addition to :func:`tap()`, Remix provides several *global functions* to make
common tasks more fluent. They reside in ``Infocyph\InterMix\Remix\functions.php``
and are automatically available (no extra ``use`` is required).

- **tap()**     – covered in :ref:`remix.tap-proxy`.
- **pipe()**    – passes a value through a callback and returns the callback’s result.
- **measure()** – times a callback and returns its result plus elapsed ms.
- **retry()**   – runs a callback repeatedly until success or max attempts.
- **once()**    – ensures a callback is evaluated only once per call site.

pipe()
======

.. php:function:: function pipe(mixed $value, callable $callback): mixed

**Goal**: Transform a value through a callback and return the result.
This is equivalent to::

   $out = $callback($value);

**Example**:

.. code-block:: php

   $sum = pipe([1, 2, 3], 'array_sum');
   // $sum === 6

measure()
=========

.. php:function:: function measure(callable $fn, ?float &$ms = null): mixed

**Goal**: Run a block of code, capture how many milliseconds it took, and
return the block’s result.

- ``$fn``: any zero-argument callback to measure.
- ``&$ms``: a float passed by reference, which will hold the elapsed time in milliseconds.

**Example**:

.. code-block:: php

   $elapsed = 0.0;
   $result  = measure(fn() => array_sum(range(1, 1000)), $elapsed);
   echo "Time taken: {$elapsed} ms";
   echo "Result: {$result}";

retry()
=======

.. php:function:: function retry(int $attempts, callable $callback, ?callable $shouldRetry = null, int $delayMs = 0, float $backoff = 1.0)

**Goal**: Keep retrying a failing operation until it succeeds or the maximum number of attempts is reached.
Ideal for flaky APIs, network calls, or transient database issues.

Parameters:

- ``$attempts``: Maximum number of tries (must be ≥ 1).
- ``$callback``: A callable that may throw on failure or return a result. Receives the current attempt number.
- ``$shouldRetry`` *(optional)*: Callable like ``fn(Throwable $e): bool`` – decides if retry should continue after an exception.
- ``$delayMs``: Initial delay before first retry (in milliseconds).
- ``$backoff``: A multiplier applied to ``delayMs`` after each failure (e.g., ``1.5`` = +50% delay increase each time).

**Behavior:**

1. Calls ``$callback(1)``.
   If successful, returns immediately.

2. If it throws an exception ``$e``:

   - If attempts exhausted, rethrows.
   - If ``$shouldRetry($e)`` returns false, rethrows.
   - Else, sleeps for ``$delayMs`` and retries with increased delay (``delayMs *= backoff``).

3. Repeats until a return value is obtained or all retries fail.

**Example**:

.. code-block:: php

   $tries = 0;
   $val = retry(
       3,
       fn($n) => (++$tries < 3)
           ? throw new RuntimeException('fail')
           : 'ok',
       shouldRetry: fn($e) => $e instanceof RuntimeException,
       delayMs: 100,
       backoff: 2.0
   );
   // After two failures, on the third try it returns "ok".

once()
======

.. php:function:: function once(callable $callback, ?Container $container = null): mixed

**Goal**: Execute a zero-argument callback exactly once **per call site** (determined by `file:line`). On the first invocation at that source location, ``once()`` runs the callback and caches its result. All subsequent calls from that same location return the stored value, never re-evaluating the callback.

- ``$callback`` – A zero-argument callable to evaluate.
- ``$container`` *(optional)* – A dependency container that supports registration via `registerClosure($key, $callback)`. If supplied, it is used instead of the internal static cache.

This ensures memoization based on where ``once()`` is called—regardless of class or function.

**Behavior:**

- If called without a container, a function-local static array stores values per `file:line`.
- If called with a container, it uses the container’s registration system to store results and return from cache.

**Example (using built-in static cache)**:

.. code-block:: php

   // First time: runs the closure, caches result
   $a = once(fn() => rand(1, 999));

   // Same line = cached result
   $b = once(fn() => rand(1, 999));
   // $a === $b

   // Different line = new execution
   $c = once(fn() => rand(1, 999)); // this is a new line = new result

**Example (with container)**:

.. code-block:: php

   $container = new ArrayContainer(); // must support registerClosure(), has(), get()

   $val = once(
       fn() => computeSomething(),
       $container
   );

   // Will always return cached value from container on subsequent calls at same line

**Use Cases**:

- Expensive initialization code
- Runtime configuration values
- Deterministic memoization per exact file+line
- Optional use of a shared container for testability or multi-scope consistency
