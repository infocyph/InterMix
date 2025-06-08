.. _remix.tap-proxy:

========================
Tap Proxy (``TapProxy``)
========================

``Infocyph\InterMix\Remix\TapProxy`` is the engine behind the zero‐argument
``tap()`` call. Whenever you write:

.. code-block:: php

   $someObject->tap()->foo()->bar()->baz();

this actually creates a ``TapProxy($someObject)``. Then each chained method
(``foo()``, ``bar()``, ``baz()``) is invoked on the real target, **but the proxy
always returns the original target** (not the proxy itself). That means your
chain never breaks, and you never have to assign the result manually.

Global Helper Function ``tap()``
================================

.. php:function:: function tap(mixed $value, ?callable $callback = null): mixed

Usage Examples
==============

.. code-block:: php

   // 1) With a callback: let me “observe” $user and still return $user.
   $user = tap($user, fn($u) => logger()->info("User id={$u->id}"));

   // 2) Proxy method chaining: call methods but keep $user at end.
   tap($user)
       ->setName('Alice')
       ->activate()
       ->sendWelcomeEmail();
