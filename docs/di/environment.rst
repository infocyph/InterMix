.. _di.environment:

=============================
Environment‑specific bindings
=============================

InterMix supports **environment-aware** service bindings – ideal for swapping
real implementations with **fakes**, **stubs**, or **vendors** based on the
current runtime environment.

---------------
Use‑Case 🎯
---------------

- In **production**, you want the real payment gateway.
- In **local/test**, you prefer a dummy or fake.

Instead of writing custom conditionals in your app, **bind per environment**:

.. code-block:: php

   $c->options()
     ->bindInterfaceForEnv('prod', PaymentGateway::class, StripeGateway::class)
     ->bindInterfaceForEnv('test', PaymentGateway::class, FakeGateway::class);

Then activate the current env:

.. code-block:: php

   $c->options()->setEnvironment($_ENV['APP_ENV'] ?? 'prod');

-------------------------------
How It Works Behind the Scenes
-------------------------------

When you resolve an interface like:

.. code-block:: php

   $gateway = $c->get(PaymentGateway::class);

The container internally checks:

1. Is `env` mode active?
2. Is there a matching `bindInterfaceForEnv()` mapping for the current env?
3. If yes, use the target class (e.g. `StripeGateway`)
4. Otherwise, fallback to global bindings or autowiring

This keeps your **business logic decoupled** from deployment configs.

--------------------------
Multiple Environments 📦
--------------------------

You may bind different interfaces for **different environments**:

.. code-block:: php

   $c->options()
     ->bindInterfaceForEnv('local', LoggerInterface::class, FileLogger::class)
     ->bindInterfaceForEnv('prod',  LoggerInterface::class, CloudLogger::class)
     ->bindInterfaceForEnv('debug', LoggerInterface::class, VerboseLogger::class);

Switch dynamically:

.. code-block:: php

   $c->options()->setEnvironment('debug');

-----------------------------
Priority & Resolution Order
-----------------------------

If multiple environments are defined, InterMix **only uses** the one explicitly
set via :php:meth:`setEnvironment`.

Resolution priority:

1. **Environment-bound class** (if active)
2. **Globally bound class** via :php:meth:`bind()`
3. **Autowire fallback** (if `injection=true`)

-------------------
Best Practices 💡
-------------------

* Use environment bindings for **external systems** (payment, mail, auth).
* Avoid overusing for things easily toggled with config values.
* Prefer strings like `"prod"`, `"test"`, `"local"` – but any name is allowed.

Want to override a tag or lifetime **per environment**? See upcoming roadmap in
the GitHub issues.

Next stop » :doc:`caching`
