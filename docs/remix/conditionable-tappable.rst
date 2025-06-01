.. _remix.conditionable-tappable:

==================================
Conditionable & Tappable Trait
==================================

`Infocyph\InterMix\Remix\ConditionableTappable` bundles three very handy
“fluent helpers” into a single trait:

  1. **when()**   – Run a callback only if a condition is *truthy*.
  2. **unless()** – Run a callback only if a condition is *falsy*.
  3. **tap()**    – “Peek” into an object chain without breaking the chain.

By including this single trait, any class gains these three methods.  They are
modeled after Laravel’s exact API, but you pay *zero* cost unless you call
them.

Why use it?
-----------

- **Fluent conditionals**
  Instead of wrapping entire code blocks in `if ($x) { … } else { … }`, you
  can chain:

  ```php
  $order->when($order->total > 100, fn($o) => $o->applyDiscount());
````

* **Inverse fluency**
  The companion `unless()` allows “if not” without confusion:

  ```php
  $user->unless($isGuest, fn($u) => $u->attachProfile($profile));
  ```

* **Tap into pipelines**
  The `tap()` method is great when you want to inspect or log intermediate
  state in a chain, but still return the original object:

  ```php
  $cart
      ->applyTax()
      ->tap(fn($c) => logger()->debug("Cart total is “{$c->total}”"))
      ->checkout();
  ```

# Usage

Enable the trait on any class:

.. code-block:: php

namespace App;

use Infocyph\InterMix\Remix\ConditionableTappable;

class Order
{
use ConditionableTappable;

```
   public float $total = 0.0;
   public bool  $discounted = false;

   public function applyDiscount(): static
   {
       $this->discounted = true;
       $this->total -= 10.0;
       return $this;
   }
```

}

````

### when()

```php
public function when(
    mixed $value = null,
    ?callable $callback = null,
    ?callable $default = null
): static|mixed
````

* **If called with no arguments**, it returns a `ConditionalProxy` that lets you
  “capture the next property or method” as the Boolean condition. See below.
* **If called with one argument `$value` only**, it returns a proxy on which
  `$value` is the condition. You can then chain property/method checks.
* **If called with `$value` + `$callback`**:

  * If `$value` is truthy (or if `$value()` returns truthy when `$value` is a
    `Closure`), it invokes `$callback($this, $value)` and returns the result
    (or `$this` if the callback returns null).
  * Otherwise, if a `$default` callback was provided, it invokes
    `$default($this, $value)` and returns that (or `$this` if null).
  * If neither applies, it simply returns `$this`.

**Example:**

```php
$order = new Order();
$order->total = 150.0;

// Only applies discount if total > 100
$order->when($order->total > 100, fn($o) => $o->applyDiscount());
// $order->discounted is now true
```

### unless()

```php
public function unless(
    mixed $value = null,
    ?callable $callback = null,
    ?callable $default = null
): static|mixed
```

Same signature as `when()`, but inverts the condition:

* If `$value` is falsy (or `$value()` returns falsy), run `$callback($this, $value)`.
* Else, if `$default` is provided, run `$default($this, $value)`.
* Otherwise return `$this`.

**Example:**

```php
$user = new User();
$user->isGuest = false;

$user->unless($user->isGuest, fn($u) => $u->attachProfile());
// Because isGuest===false, attachProfile() runs.
```

### tap()

```php
public function tap(?callable $callback = null): static|TapProxy
```

* **With a `$callback`**: simply calls ` $callback($this)` and returns `$this`.

  * Great for logging or debugging mid-chain.

* **With no arguments**: returns a `TapProxy` wrapping `$this`.  Any method you
  call on that proxy is “forwarded” to the target, but the proxy always returns
  the *original* object itself at the end.

**Examples:**

```php
// 1) Immediate peek + return
$cart->tap(fn($c) => logger()->info("Cart total {$c->total}"))->checkout();

// 2) Proxy style
$cart
    ->addItem($item1)
    ->tap()         // returns TapProxy($cart)
        ->log("just after addItem")
        ->applyTax()
    ->checkout();
```

### Zero-argument proxy capture

When you call `when()` or `unless()` **with zero arguments**, you get a
`ConditionalProxy` (not the original object).  The proxy waits to “capture”
the next property or method invocation as the condition. Example:

```php
$user = new User();
$user->active = true;

// Because ->active is read, condition = true, so ->sendNewsletter() runs.
$user->when()->active->activate();

// If that property or method returns false, the chain short-circuits
$user->when()->isActive()->status = 'OK';
// Here isActive() is false, so “status” is not set.
```

Internally, `ConditionalProxy` uses PHP magic (`__get`, `__call`) to check
“have I already captured a condition?” and either evaluate the callback or
return `$this`.
