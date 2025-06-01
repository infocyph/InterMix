.. _remix.tap-proxy:

========================
Tap Proxy (`TapProxy`)
========================

`Infocyph\InterMix\Remix\TapProxy` underpins ``tap()`` when it is invoked
*without* a callback:

.. code-block:: php

   tap($user)
       ->updateLastSeen()
       ->notify('Welcome back!');
   // returns the original $user instance

Any method you call on the proxy is forwarded to the original target,
and the proxy then yields **the target again**, keeping chains alive.

This global helper is defined in ``Remix\functions.php``:

.. code-block:: php

   function tap(mixed $value, ?callable $cb = null): mixed
