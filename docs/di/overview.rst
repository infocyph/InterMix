.. _di.overview:

========
Overview
========

InterMix is a **“zero-config-until-you-want-it”** dependency-injection container.
Start with *one* line, stay productive when your project grows – lifetimes,
scopes, debug tracing, Symfony cache, preload generation … all optional.

Why another container?
----------------------

* **Simple first** – one-liner definitions, no config files
* **Reflection-aware** – autowiring you can *switch off*
* **Attribute powered** – `#[Infuse]`, `#[Autowire]`, `#[Inject]`
* **Fluent API** – four tiny managers that chain like one object
* **Performant** – static reflection cache, optional PSR-6/16 cache,
  lazy services by default
* **Production ready** – env-specific bindings, scoped lifetimes,
  preload file generator
* **Debuggable** – built-in tracer (node / verbose)

15-second “hello world”
~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   use function Infocyph\InterMix\container;

   interface Clock { public function now(): DateTimeImmutable; }
   class SystemClock implements Clock
   { public function now(): DateTimeImmutable { return new DateTimeImmutable(); } }

   class Greeter
   {
       public function __construct(private Clock $clock) {}
       public function greet(string $name): string
       {
           return 'Hello '.$name.' — '.$this->clock->now()->format('c');
       }
   }

   $c = container()
       ->definitions()->bind(Clock::class, SystemClock::class)->end();

   echo $c->get(Greeter::class)->greet('Alice');
   // → “Hello Alice — 2025-06-18T12:34:56+00:00”

Managers & call chain
---------------------

Every manager shares a tiny *proxy* trait – so you can hop around fluently and
still land back on the main container:

.. code-block:: php

   $c->definitions()
         ->bind(Logger::class, FileLogger::class)
         ->options()
             ->setOptions(injection:true)
             ->enableLazyLoading()
             ->end()
         ->registration()
             ->registerClass(App::class)
             ->end()
         ->invocation()
             ->call(App::class, 'boot')
             ->end()
       ->lock();

Ascii peek
~~~~~~~~~~

::

                       +---------------------+
                       |  DefinitionManager  |
                       +----------+----------+
                                  ^
                                  | .definitions()
    +-----------------+ .options  |           | .registration()
    |  OptionsManager +-----------+           v
    +-----------------+                       +---------------------+
                                                | RegistrationManager|
                               .invocation()    +----------+--------+
                                                ^          |
                                                |          |
                                                |          v
                                           +----+---------------+
                                           |  InvocationManager |
                                           +--------------------+

The shared **Repository** (not pictured) stores:

* **functionReference** – your definitions
* **classResource** – extra constructor / method / property data
* **resolved / resolvedDefinition** – cached objects & values
* **conditionalBindings** – environment overrides

How resolution works
--------------------

When you call ``get()``, ``getReturn()`` or ``call()`` InterMix walks the
pipeline below – applying *lazy placeholders*, *caching* and *autowiring* as
needed.

#. **Already resolved?**
   * Return immediately if found in the in-memory cache.
   * If the cache entry is a :php:class:`DeferredInitializer` *lazy* wrapper,
     execute it now and swap in the real object.

#. **FunctionReference lookup**
   If the ID exists in your *definitions*, InterMix runs
   ``resolveDefinition($id)`` which
   honours caching, env overrides, user closures, etc.

#. **Fallback: class name**
   If autowiring is **on**, reflection builds the class (constructor injection,
   property/parameter attributes, method call).
   If autowiring is **off**, the lightweight
   :php:class:`GenericCall` path instantiates without reflection magic.

#. **Cache layer**
   With definition caching enabled, Symfony cache is consulted. Resolution
   results are stored back for next time.

User closure vs. lazy
~~~~~~~~~~~~~~~~~~~~~

* **User-supplied closure**

  .. code-block:: php

     $c->definitions()->bind('heavy', fn () => new Expensive());

  is executed **immediately** – you asked for a closure.

* **DeferredInitializer**

  For class strings/arrays *and* ``enableLazyLoading(true)``, InterMix stores a
  small wrapper and postpones construction until the first real ``get()``.

Concurrency note
~~~~~~~~~~~~~~~~

Reflection metadata lives in a **static cache**. In common *process-per-request*
set-ups (PHP-FPM, CLI), this is safe.
In rare multi-thread situations (Swoole, ReactPHP, pthreads) you might clear or
synchronise that cache manually.

Typical lifecycle
-----------------

1. **Create** a container (alias per app / test / worker)
2. **Bind & register** – definitions, classes, methods, properties
3. **Tune options** – autowire on/off, attributes, environment, cache …
4. **Resolve** with ``get() / call() / make() / getReturn()``
5. *(optional)* **Lock** the container to freeze configuration

Feature menu
------------

+ **:doc:`quickstart`** – hands-on tour
+ **:doc:`definitions`** / **:doc:`registration`** – service wiring
+ **:doc:`options`** – switches & environment overrides
+ **:doc:`attributes`** – `#[Infuse]` / `#[Autowire]` / `#[Inject]`
+ **:doc:`lifetimes`** – singleton / transient / scoped
+ **:doc:`scopes`** – request / fibre isolation
+ **:doc:`lazy_loading`**, **:doc:`caching`**, **:doc:`debug_tracing`**
+ **:doc:`cheat_sheet`** – one-page reference

Next steps
----------

If you like to learn by **code**, jump straight to :doc:`quickstart`.
Prefer concepts first? start with :doc:`understanding`.
Either way – **happy mixing!**
