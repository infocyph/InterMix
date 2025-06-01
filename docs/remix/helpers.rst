.. _remix.helpers:

=========================
Global Helper Functions
=========================

In addition to `tap()`, Remix provides several *global functions* to make
common tasks more fluent. They live in `Infocyph\InterMix\Remix\functions.php`
and are automatically available (no extra `use` required).

- **tap()**    – covered in :ref:`remix.tap-proxy`.
- **pipe()**   – passes a value through a callback and returns the callback’s result.
- **measure()**– times a callback and returns its result plus elapsed ms.
- **retry()**  – runs a callback repeatedly until success or max attempts.

### pipe()

```php
function pipe(mixed $value, callable $callback): mixed
````

**Goal**: When you want to transform a value and immediately grab the
callback’s return (instead of getting the original value back).
Equivalent to:

```php
$out = $callback($value);
```

**Example:**

```php
$sum = pipe([1, 2, 3], 'array_sum');
// $sum === 6
```

### measure()

```php
function measure(callable $fn, ?float &$ms = null): mixed
```

**Goal**: Run a block of code, capture how many milliseconds it took, and
return the block’s return value.

* **\$fn** – any zero-argument callback to measure.
* **&\$ms** – a float variable (passed by reference) that will be set to the
  elapsed time in **milliseconds**.

**Example:**

```php
$elapsed = 0.0;
$result  = measure(fn() => array_sum(range(1, 1000)), $elapsed);
echo "Time taken: {$elapsed} ms";
echo "Result: {$result}";
```

### retry()

```php
function retry(
    int $attempts,
    callable $callback,
    ?callable $shouldRetry = null,
    int $delayMs = 0,
    float $backoff = 1.0
): mixed
```

**Goal**: Keep trying a failing operation until it succeeds or you hit the
maximum number of retries.  Useful for flaky network calls, transient errors,
etc.

* **\$attempts**  – Maximum number of tries (≥ 1).
* **\$callback**  – A callable that may throw an exception on failure, or
  return a value on success. Receives the *current attempt count* as its first
  argument.
* **\$shouldRetry** – Optional function `fn(Throwable $e): bool` ➝ true/false.
  After a catch, if it returns `false`, we immediately rethrow.
* **\$delayMs**     – Milliseconds to wait *before* first retry.
* **\$backoff**     – Multiply `delayMs` by this factor after each failure.
  e.g. `1.5` will add a +50% delay every time.

**Behavior**:

1. Call `($callback)(1)`.  If it returns without throwing, you return that
   value immediately.
2. If it throws `$e`, check:

   * If attempt ≥ `$attempts`, rethrow.
   * If `$shouldRetry` is provided and returns `false`, rethrow.
   * Otherwise, sleep `$delayMs` ms, then multiply `$delayMs ×= $backoff` and
     retry with attempt = 2.
3. Repeat until success or exhausting attempts.

**Example:**

```php
$tries = 0;
$val   = retry(
    3,
    fn($n) => (++$tries < 3)
        ? throw new RuntimeException('fail')
        : 'ok',
    shouldRetry: fn($e) => $e instanceof RuntimeException,
    delayMs: 100,
    backoff: 2.0
);
// After two failures, on the third try it returns "ok".
```
