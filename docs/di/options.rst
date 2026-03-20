.. _di.options:

=========================
Options & Feature Toggles
=========================

The **OptionsManager** (which utilizes the `ManagerProxy` trait) lets you fine-tune *how* InterMix behaves.
Option toggles are configured via explicit methods:

.. code-block:: php

   // Using method chaining (fluent interface)
   $c->options()
       ->setOptions(               // ← the four primary flags
           injection:           true,
           methodAttributes:    true,
           propertyAttributes:  true,
           defaultMethod:       'handle',
       )
       ->enableLazyLoading()       // ← convenience helpers
       ->setEnvironment('prod')
       ->enableDebugTracing()      // collect a build trace
       ->end();                    // back to container

----------------------------------------------------
1 · setOptions( injection , methodAttributes , … )
----------------------------------------------------

======================  =========  ==========================================================
Flag                    Default    What it does
======================  =========  ==========================================================
``injection``           ``true``   Turn the **reflection autowiring engine** on / off.
                                  When *false* the container switches to :php:`GenericCall`
                                  and **every** dependency must be supplied via
                                  :ref:`di.registration`.
``methodAttributes``    ``false``  Honour :php:`#[Infuse]` on **method parameters**.
``propertyAttributes``  ``false``  Honour :php:`#[Infuse]` on **class properties**.
``defaultMethod``       ``null``   If you call :php:`getReturn(Foo::class)` **without** a
                                  registered method, the container will execute this method
                                  on the freshly-built instance (e.g. ``'__invoke'`` or
                                  ``'handle'`` in CQRS/HTTP handlers).
======================  =========  ==========================================================

*Signature* (named arguments supported) ::

   setOptions(
       bool        $injection          = true,
       bool        $methodAttributes   = false,
       bool        $propertyAttributes = false,
       ?string     $defaultMethod      = null
   ): self

----------------------------------------------------
2 · Convenience helpers (chainable)
----------------------------------------------------

+---------------------------------------------+--------------------------------------------------------------+
| **Helper**                                  | **Effect**                                                   |
+=============================================+==============================================================+
| ``enableLazyLoading(true)``                 | Store **class bindings** as :php:`DeferredInitializer` until |
|                                             | the first :php:`get($id)`. Reduces boot cost.                |
+---------------------------------------------+--------------------------------------------------------------+
| ``setEnvironment('prod')``                  | Select the *current* environment. Used together with         |
|                                             | ``bindInterfaceForEnv()`` to swap implementations.           |
+---------------------------------------------+--------------------------------------------------------------+
| ``bindInterfaceForEnv($env, I::class, C::class)`` | Map an **interface → concrete** **only** when            |
|                                             | ``$env`` matches the active environment.                     |
+---------------------------------------------+--------------------------------------------------------------+
| ``enableDebugTracing(true, TraceLevelEnum::Verbose)`` | Capture **resolution traces** for debugging. See   |
|                                             | :ref:`di.debug_tracing`.                                     |
+---------------------------------------------+--------------------------------------------------------------+

----------------------------------------------------
3 · Practical primer
----------------------------------------------------

**Local development** – verbose trace & eager loading:

.. code-block:: php

   container()
       ->options()
       ->setOptions(
           injection: true,
           methodAttributes: true,
           propertyAttributes: true,
       )
       ->enableLazyLoading(false)          // eager
       ->enableDebugTracing()              // verbose logs
       ->setEnvironment('local');

**Production** – lazy, cached, minimal reflection:

.. code-block:: php

   $c = container()
       ->options()
       ->setOptions(
           injection: true,                // keep autowiring
           methodAttributes: false,        // skip attribute scanning
           propertyAttributes: false,
       )
       ->enableLazyLoading(true)           // default – save memory
       ->setEnvironment('prod')
       ->end();

   // definition cache is configured on DefinitionManager
   $c->definitions()->enableDefinitionCache();

----------------------------------------------------
4 · Inspecting state
----------------------------------------------------

.. code-block:: php

   $repo = $c->getRepository();
   dump([
       'environment' => $repo->getEnvironment(),
       'lazy'        => $repo->isLazyLoading(),
   ]);

----------------------------------------------------
Cheat-Sheet
----------------------------------------------------

* **Four core flags** live in **setOptions()**.
* Everything else is sugar via dedicated helpers.
* Call **->end()** to return to the container and continue the fluent chain.

See also: :ref:`di.environment`, :ref:`di.lazy_loading`, :ref:`di.debug_tracing`.
