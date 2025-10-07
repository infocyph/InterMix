.. _di.definitions:

=========================
Definition Manager API
=========================

``$c->definitions()`` returns an **instance-fluent** manager that stores **recipes**
(**definitions**) by *ID*.
Everything that can be resolved by :php:method::`Infocyph\InterMix\DI\Container->get`
ultimately lives in this registry.

---------------------------------------------------
1.  Binding values, classes & factories
---------------------------------------------------

.. code-block:: php

   $def = $c->definitions();

   // 💠 scalars / plain values
   $def->bind('app.name',    'InterMix Demo');
   $def->bind('answer',      42);

   // 💠 class-string → auto–resolve on first get()
   $def->bind('clock', DateTimeImmutable::class);

   // 💠 factory closure
   $def->bind('uuid', fn () => bin2hex(random_bytes(16)));

You may chain calls – the manager is **fluent** and `->end()` brings you back to
the container:

.. code-block:: php

   $c->definitions()
       ->bind('foo', 123)
       ->bind('bar', 456)     // returns $c
     ->lock();

-----------------------------------------------
2.  Lifetimes (Singleton ⇢ Scoped ⇢ Transient)
-----------------------------------------------

.. code-block:: php

   use Infocyph\InterMix\DI\Support\Lifetime;

   // default = Singleton
   $def->bind('uniq', fn() => new stdClass());                 // same instance forever

   // Transient – fresh each time
   $def->bind('once', fn() => new stdClass(), Lifetime::Transient);

   // Scoped – unique per “scope” key
   $def->bind('req', fn() => new stdClass(), Lifetime::Scoped);

   $obj1 = $c->get('req');
   $c->getRepository()->setScope('next-request');
   $obj2 = $c->get('req');          // ⚠️ not equal to $obj1

Lifetimes apply **equally** to class-string bindings – InterMix transparently converts them
into internal lazy initialisers.

-----------------------------------------------
3.  Tags – collect related services
-----------------------------------------------

.. code-block:: php

   $def->bind('L1', fn () => new ListenerA(), tags: ['event']);
   $def->bind('L2', fn () => new ListenerB(), tags: ['event']);

   foreach ($c->findByTag('event') as $id => $factory) {
       $factory()->handle();
   }

Use tags for plug-in systems, domain events, command buses, etc.

----------------------------------------------------
4.  Bulk import & sugar syntax
----------------------------------------------------

**Array import**

.. code-block:: php

   $def->addDefinitions([
       'db.host'            => '127.0.0.1',
       LoggerInterface::class => FileLogger::class,   // interface ⇒ concrete
   ]);

**Property / array magic** (handy for tests & prototyping) – available on both the
container *and* the manager thanks to *ManagerProxy*:

.. code-block:: php

   $c->logger = fn () => new DummyLogger();          // property
   $c['cfg']  = fn () => ['debug' => true];          // array access

   $log = $c->logger;          // magic __get
   $cfg = $c('cfg');           // __invoke

----------------------------------------------------
5.  Lazy loading — opt-in or opt-out
----------------------------------------------------

Definitions default to **lazy placeholders** *(cheap objects holding a closure)*,
resolved the **first** time you call ``get('service')``.

Toggle globally:

.. code-block:: php

   $c->options()->enableLazyLoading(false);   // eager – resolve immediately

User-supplied **closures** are **never** lazy – the closure executes at bind-time so
the value in the container is already the *result*.  This keeps the mental model
intuitive: *“I gave you a closure, give me back its return.”*

----------------------------------------------------
6.  Environment-aware bindings  (quick reminder)
----------------------------------------------------

Although technically part of :ref:`di.options`, the Definition Manager plays nice with
**environment overrides** declared in ``options()`` – when you ``bind(Interface::class, Concrete::class)``
the container substitutes the correct concrete based on the current environment
at resolve-time.

----------------------------------------------------
What’s next?
----------------------------------------------------

Need to register **constructor parameters**, **method calls** or **properties**?
Head to :ref:`di.registration`.
Want to see all manager calls in a cheat sheet? ― :ref:`di.cheat_sheet`.
