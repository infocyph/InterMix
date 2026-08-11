.. _remix.tap-proxy:

========================
Tap Proxy (``TapProxy``)
========================

``Infocyph\InterMix\Remix\TapProxy`` is the engine behind the zero‐argument
``tap()`` call. Whenever you write:

.. code-block:: php

   $someObject->tap()->foo();

this creates a ``TapProxy($someObject)``. The forwarded ``foo()`` call runs on
the real target and the proxy returns that original target, regardless of
``foo()``'s return value. Any following ``bar()`` or ``baz()`` call is therefore
ordinary target behavior; it is not intercepted by the same tap proxy.

Global Helper Function ``tap()``
================================

.. php:function:: tap(mixed $value, ?callable $callback = null): mixed

Usage Examples
==============

.. code-block:: php

   // 1) With a callback: let me “observe” $user and still return $user.
   $user = tap($user, fn($u) => logger()->info("User id={$u->id}"));

   // 2) Call one method but keep $user as the expression result.
   $user = tap($user)->setName('Alice');
