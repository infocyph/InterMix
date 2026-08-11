.. _di.options:

=========================
Options & Feature Toggles
=========================

The **OptionsManager** (which uses the ``ManagerProxy`` trait) lets you fine-tune *how* InterMix behaves.
Option toggles are configured via explicit methods.
Because this manager proxies container access, you can also call container APIs directly from it (for example ``get()``, ``has()``, or magic/array sugar like ``$opt('id')`` and ``$opt['id']``).

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

.. list-table::
   :header-rows: 1
   :widths: 25 15 60

   * - Flag
     - Default
     - What it does
   * - ``injection``
     - ``true``
     - Turn the **reflection autowiring engine** on or off. When *false*, the
       container switches to ``GenericCall`` and **every** dependency must be
       supplied via :ref:`di.registration` or an explicit direct factory.
   * - ``methodAttributes``
     - ``false``
     - Honour ``#[Inject]`` on **method parameters**.
   * - ``propertyAttributes``
     - ``false``
     - Honour ``#[Inject]`` on **class properties**.
   * - ``defaultMethod``
     - ``null``
     - If you call ``getReturn(Foo::class)`` **without** a registered method,
       the container executes this method on the freshly built instance (for
       example, ``'__invoke'`` or ``'handle'`` in CQRS/HTTP handlers).

*Signature* (named arguments supported) ::

   setOptions(
       bool        $injection          = true,
       bool        $methodAttributes   = false,
       bool        $propertyAttributes = false,
       ?string     $defaultMethod      = null
   ): self

Direct factories registered with ``bindFactory()`` or ``factory()`` have the
same behavior in both modes: InterMix calls the factory with its owning
container and never reflects or autowires the factory parameters.

----------------------------------------------------
2 · Convenience helpers (chainable)
----------------------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 40 60

   * - Helper
     - Effect
   * - ``enableLazyLoading(true)``
     - Store class bindings as internal deferred entries until first ``get($id)``; reduces boot cost.
   * - ``setEnvironment('prod')``
     - Select the active environment; used with ``bindInterfaceForEnv()`` to swap implementations.
   * - ``bindInterfaceForEnv($env, I::class, C::class)``
     - Map interface to concrete only when ``$env`` matches the active environment.
   * - ``enableDebugTracing(true, TraceLevelEnum::Verbose)``
     - Capture resolution traces for debugging. Pass ``false`` to fully disable tracing (sets level to ``Off``). See :ref:`di.debug_tracing`.

----------------------------------------------------
3 · Practical primer
----------------------------------------------------

**Local development** – verbose tracing and direct future bindings:

.. code-block:: php

   container()
       ->options()
       ->setOptions(
           injection: true,
           methodAttributes: true,
           propertyAttributes: true,
       )
       ->enableLazyLoading(false)          // do not wrap future class bindings in placeholders
       ->enableDebugTracing()              // Node-level logs
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
       ->enableDebugTracing(false)         // fully off (TraceLevelEnum::Off)
       ->enableLazyLoading(true)           // default – save memory
       ->setEnvironment('prod')
       ->end();

   // definition cache is configured on DefinitionManager with a PSR-6 pool
   $c->definitions()->enableDefinitionCache($pool);

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
