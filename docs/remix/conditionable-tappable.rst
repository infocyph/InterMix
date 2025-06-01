.. _remix.conditionable-tappable:

=========================================
Conditionable & Tappable Trait
=========================================

The ``ConditionableTappable`` trait bundles three very handy fluent helpers into a single utility:

1. **when()** – Run a callback only if a condition is *truthy*.
2. **unless()** – Run a callback only if a condition is *falsy*.
3. **tap()** – “Peek” into an object chain without breaking the chain.

By including this single trait, any class gains these three methods. They are modeled after Laravel’s API, but incur no overhead unless used.

Why use it?
===========

**Fluent conditionals**

Instead of wrapping code in::

    if ($x) {
        ...
    }

you can write::

    $order->when($order->total > 100, fn($o) => $o->applyDiscount());

**Inverse fluency**

The companion ``unless()`` allows “if not” without confusion::

    $user->unless($isGuest, fn($u) => $u->attachProfile($profile));

**Tap into pipelines**

The ``tap()`` method is helpful to inspect or log intermediate states in a chain::

    $cart
        ->applyTax()
        ->tap(fn($c) => logger()->debug("Cart total is {$c->total}"))
        ->checkout();

Usage
=====

Enable the trait on any class::

    namespace App;

    use Infocyph\InterMix\Remix\ConditionableTappable;

    class Order
    {
        use ConditionableTappable;

        public float $total = 0.0;
        public bool  $discounted = false;

        public function applyDiscount(): static
        {
            $this->discounted = true;
            $this->total -= 10.0;
            return $this;
        }
    }

when()
======

.. code-block:: php

    public function when(
        mixed $value = null,
        ?callable $callback = null,
        ?callable $default = null
    ): static|mixed

**Behavior:**

- **No arguments:** Returns a ``ConditionalProxy`` that captures the next property or method as the condition.
- **One argument `$value`:** Returns a proxy using `$value` as the condition.
- **With `$value` and `$callback`:**
  - If `$value` is truthy (or `$value()` returns truthy), runs `$callback($this, $value)` and returns the result (or `$this` if null).
  - Otherwise, if a `$default` is provided, runs `$default($this, $value)` and returns that (or `$this` if null).
  - If neither applies, returns `$this`.

**Example:**

.. code-block:: php

    $order = new Order();
    $order->total = 150.0;

    // Only applies discount if total > 100
    $order->when($order->total > 100, fn($o) => $o->applyDiscount());
    // $order->discounted is now true

unless()
========

.. code-block:: php

    public function unless(
        mixed $value = null,
        ?callable $callback = null,
        ?callable $default = null
    ): static|mixed

**Behavior:**

Same as ``when()``, but with inverted condition:

- If `$value` is falsy (or `$value()` returns falsy), run `$callback($this, $value)`.
- Else, if `$default` is provided, run `$default($this, $value)`.
- Otherwise return `$this`.

**Example:**

.. code-block:: php

    $user = new User();
    $user->isGuest = false;

    $user->unless($user->isGuest, fn($u) => $u->attachProfile());
    // Because isGuest === false, attachProfile() runs.

tap()
=====

.. code-block:: php

    public function tap(?callable $callback = null): static|TapProxy

**Behavior:**

- **With `$callback` provided:** Executes ``$callback($this)`` and returns `$this`.
- **With no arguments:** Returns a ``TapProxy``. Any method you call on it will run, but return the original object.

**Examples:**

.. code-block:: php

    // Immediate callback and return
    $cart->tap(fn($c) => logger()->info("Cart total {$c->total}"))->checkout();

    // Proxy chaining
    $cart
        ->addItem($item1)
        ->tap()         // returns TapProxy($cart)
            ->log("after addItem")
            ->applyTax()
        ->checkout();

Zero-argument Proxy Capture
===========================

Calling ``when()`` or ``unless()`` with zero arguments returns a ``ConditionalProxy``. This proxy “captures” the next method or property to determine whether the condition is truthy or falsy.

.. code-block:: php

    $user = new User();
    $user->active = true;

    // Since ->active is truthy, activate() runs
    $user->when()->active->activate();

    // Suppose isActive() returns false
    $user->when()->isActive()->status = 'OK';
    // Because isActive() is false, status is not set.

Internals
=========

The ``ConditionalProxy`` uses PHP magic methods ``__get()`` and ``__call()`` to intercept the first interaction and then forward or suppress behavior based on the evaluated condition.
