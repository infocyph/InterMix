.. _di.lifetimes:

===================
Service Lifetimes
===================

InterMix supports three configurable **lifetimes** via
:php:class:`Infocyph\InterMix\DI\Support\Lifetime`.

This allows fine-grained control over how instances are reused or regenerated.

+--------------+------------------------------------------+
| Lifetime     | Description                              |
+==============+==========================================+
| Singleton    | One shared instance per container alias  |
+--------------+------------------------------------------+
| Transient    | A new instance **every time**            |
+--------------+------------------------------------------+
| Scoped       | One instance **per scope ID**            |
+--------------+------------------------------------------+

---------------
Basic Example
---------------

.. code-block:: php

   use Infocyph\InterMix\DI\Support\Lifetime;

   $def->bind('uniq', fn () => new stdClass, Lifetime::Singleton);
   $def->bind('tmp',  fn () => new stdClass, Lifetime::Transient);
   $def->bind('req',  fn () => new stdClass, Lifetime::Scoped);

---------------
Scope Switching
---------------

Scoped services are tied to an identifier. You can switch scopes like this:

.. code-block:: php

   $c->getRepository()->setScope('request-42');

This creates a fresh "bucket" for services marked as `Scoped`, so each
scope can have independent instances without affecting others.

------------------------
When to Use What?
------------------------

✅ **Singleton** – default. Use for shared services (e.g., config, logger).

✅ **Transient** – stateless classes or builders where isolation is preferred.

✅ **Scoped** – per-request or per-job lifetimes (useful in web or queue contexts).

---------------------
Best Practices 💡
---------------------

* Don’t overuse `Transient` unless necessary — caching saves performance.
* Use `Scoped` with request-specific data or tenant-aware resolution.
* Each container alias (`Container::instance('xyz')`) has its own Singleton set.

-----------
Summary 📚
-----------

+ **Singleton** – one per container instance
+ **Transient** – fresh each time
+ **Scoped** – one per logical scope
+ Managed via `Lifetime::*` constants on any `bind()`

See also: :ref:`di.repository` for scope APIs.
