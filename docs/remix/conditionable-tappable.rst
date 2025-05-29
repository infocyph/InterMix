.. _remix.conditionable-tappable:

==================================
Conditionable & Tappable Trait
==================================

`Infocyph\InterMix\Remix\ConditionableTappable`
combines three convenience helpers in one trait:

* ``when()`` – execute a callback only when a condition is *truthy*
* ``unless()`` – the inverse of ``when()``
* ``tap()`` – peek into an object chain without breaking fluency

Quick Example
=============

.. code-block:: php

   class Order {
       use ConditionableTappable;
       public int $total = 0;
   }

   // Chain conditionally
   $order->when($order->total > 100, fn ($o) => $o->applyDiscount());

   // Unless …
   $order->unless($isGuest, fn ($o) => $o->attachUser($user));

   // Peek into the chain
   $order->tap(fn ($o) => logger()->debug($o->total))
         ->checkout();

Zero-argument Proxies
---------------------

Calling ``when()``/``unless()``/``tap()`` **without arguments** returns a
*proxy* that lets you capture the next property or method as the
condition, e.g.

.. code-block:: php

   $user->when()->isActive->sendNewsletter();
