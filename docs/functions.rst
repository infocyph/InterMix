.. _functions:

==========================
Global Functions Reference
==========================

Global helpers are optional.
InterMix does **not** autoload ``src/functions.php`` by default.
Load helpers manually when you want them:

.. code-block:: php

   require_once __DIR__ . '/vendor/infocyph/intermix/src/functions.php';

InterMix 11 does not expose DI container globals. Build and own DI runtimes
explicitly with ``ContainerBuilder`` and ``RuntimeContainerInterface`` as
documented in :ref:`di.quickstart` and :ref:`di.overview`.

The optional global helper file contains only the small functional helpers below.

Functional Helpers
------------------

tap()
=====

Pass a value to a callback and return the original value. Without a callback,
``tap()`` returns a ``TapProxy`` for fluent side effects.

See :ref:`remix.tap-proxy`.

when()
======

Apply the truthy callback when the value is truthy. When the value is falsy,
the optional falsy callback is used; otherwise the original value is returned.

See :ref:`remix.helpers`.

pipe()
======

Pass a value to a callback and return the callback result.

See :ref:`remix.helpers`.

measure()
=========

Execute a callback, return its result, and write elapsed milliseconds to the
optional by-reference timing argument.

See :ref:`remix.helpers`.

retry()
=======

Execute a callback up to the configured number of attempts with optional retry
filtering, delay, and multiplicative backoff. The last failure is rethrown when
the operation never succeeds.

See :ref:`remix.helpers`.
