.. _di.cheat_sheet:

=================
DI Cheat Sheet
=================

Quick reference for the current InterMix DI API.

-------------------------
Container Entry Points
-------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Action
     - API
   * - Create/get instance
     - ``container()`` / ``Container::instance('app')`` / ``resolve(null, [], 'app')``
   * - Get manager
     - ``$c->definitions()`` / ``$c->registration()`` / ``$c->options()`` / ``$c->invocation()``
   * - Resolve by ID/class
     - ``$c->get($id)``
   * - Resolve + execute default/registered method
     - ``$c->getReturn(Foo::class)``
   * - Build class (optional method)
     - ``$c->make(Foo::class, false|'run')``
   * - Call closure/function/class/method
     - ``$c->call($target, $method)``
   * - Scopes
     - ``$c->enterScope('req-1')`` / ``$c->leaveScope()`` / ``$c->withinScope('req-1', fn () => ...)``
   * - Tags / tracing / graph
     - ``$c->findByTag('event')``, ``$c->debug($id)``, ``$c->tracer()->toArray()``, ``$c->exportGraph()``
   * - Freeze config
     - ``$c->lock()``

-----------------------------
Managers At A Glance
-----------------------------

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Manager
     - Core methods
   * - ``definitions()``
     - ``bind()``, ``addDefinitions()``, ``enableDefinitionCache()``, ``cacheAllDefinitions()``, ``setMetaForEnv()``
   * - ``registration()``
     - ``registerClass()``, ``registerMethod()``, ``registerProperty()``, ``registerClosure()``, ``import()``
   * - ``options()``
     - ``setOptions()``, ``enableLazyLoading()``, ``setEnvironment()``, ``bindInterfaceForEnv()``, ``setDefinitionMetaForEnv()``, ``enableDebugTracing()``, ``registerAttributeResolver()``, ``generatePreload()``
   * - ``invocation()``
     - ``call()``, ``make()``, ``get()``, ``getReturn()``, ``has()``

All managers use ``ManagerProxy``: ``$mgr('id')``, ``$mgr->id``, ``$mgr['id']``, proxied container methods and ``->end()`` to return to the container.

------------------------------------------
Task Matrix (Fluent vs Shortcut)
------------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 26 37 37

   * - Task
     - Fluent chain
     - Shortcut on container
   * - Bind definition
     - ``$c->definitions()->bind('answer', 42)``
     - -
   * - Register constructor map
     - ``$c->registration()->registerClass(Foo::class)``
     - -
   * - Set options
     - ``$c->options()->setOptions(...)``
     - ``$c->enableLazyLoading(true)``
   * - Resolve service
     - ``$c->invocation()->get(Foo::class)``
     - ``$c->get(Foo::class)``
   * - Resolve return value
     - ``$c->invocation()->getReturn(Foo::class)``
     - ``$c->getReturn(Foo::class)``
   * - Call target
     - ``$c->invocation()->call($target)``
     - ``$c->call($target)``
   * - Build target
     - ``$c->invocation()->make(Foo::class)``
     - ``$c->make(Foo::class)``

----------------------
Common Recipes
----------------------

Bootstrap chain:

.. code-block:: php

   $c->definitions()
       ->bind(LoggerInterface::class, FileLogger::class)
       ->registration()
       ->registerClass(App::class, ['name' => 'InterMix'])
       ->options()
       ->setOptions(injection: true, methodAttributes: true)
       ->enableLazyLoading(true)
       ->end();

Environment-specific binding + metadata:

.. code-block:: php

   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   $c->options()
       ->bindInterfaceForEnv('prod', MailerInterface::class, SmtpMailer::class)
       ->bindInterfaceForEnv('test', MailerInterface::class, FakeMailer::class)
       ->setDefinitionMetaForEnv('test', 'mailer', LifetimeEnum::Transient, ['core', 'test-only'])
       ->setEnvironment('test');

Definition cache warmup:

.. code-block:: php

   $c->definitions()
       ->enableDefinitionCache('intermix')
       ->cacheAllDefinitions(forceClearFirst: true);

Scoped resolution:

.. code-block:: php

   $result = $c->withinScope('request-42', function () use ($c) {
       return $c->get(RequestContext::class);
   });

---------------------------
Advanced Helpers
---------------------------

* ``$c->parseCallable($spec)``: normalize closure/function/class/method input.
* ``$c->resolveNow(...)``: resolve with explicit runtime knobs.
* ``$c->getRepository()``: inspect low-level runtime state.
* ``$c->setResolverClass(FooResolver::class)``: swap resolver implementation.

See also: :ref:`di.quickstart`, :ref:`di.definitions`, :ref:`di.registration`, :ref:`di.options`, :ref:`di.invocation`, :ref:`di.scopes`, :ref:`di.environment`, :ref:`di.debug_tracing`
