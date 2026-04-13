.. _remix.helpers:

=========================
Global Helper Functions
=========================

In addition to :php:func:`tap`, Remix provides several *global functions* to make
common tasks more fluent. They are declared in ``src/functions.php`` and are
available as global helpers.

- **tap()**     – covered in :ref:`remix.tap-proxy`.
- **when()**    – conditionally apply callbacks to values.
- **pipe()**    – passes a value through a callback and returns the callback’s result.
- **measure()** – times a callback and returns its result plus elapsed ms.
- **retry()**   – runs a callback repeatedly until success or max attempts.

For DI-oriented global helpers such as ``container()``, ``resolve()`` and ``direct()``,
see :ref:`functions`.

pipe()
======

.. php:function:: pipe(mixed $value, callable $callback): mixed

**Goal**: Transform a value through a callback and return the result.
This is equivalent to::

   $out = $callback($value);

**Example**:

.. code-block:: php

   $sum = pipe([1, 2, 3], 'array_sum');
   // $sum === 6

when()
======

.. php:function:: when(mixed $value, callable $truthy, ?callable $falsy = null): mixed

**Goal**: Apply one callback for truthy values and optionally another callback
for falsy values.

- If ``$value`` is truthy, returns ``$truthy($value)``.
- If ``$value`` is falsy and ``$falsy`` exists, returns ``$falsy($value)``.
- Otherwise, returns ``$value`` unchanged.

**Example**:

.. code-block:: php

   $status = when(
       0,
       fn (int $v) => "ok:{$v}",
       fn (int $v) => "fallback:{$v}"
   );
   // $status === "fallback:0"

measure()
=========

.. php:function:: measure(callable $fn, ?float &$ms = null): mixed

**Goal**: Run a block of code, capture how many milliseconds it took and
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

.. php:function:: retry(int $attempts, callable $callback, ?callable $shouldRetry = null, int $delayMs = 0, float $backoff = 1.0)

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
