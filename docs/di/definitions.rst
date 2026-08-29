.. _di.definitions:

=========================
Definition Manager API
=========================

``$c->definitions()`` returns an **instance-fluent** manager that stores **recipes**
(**definitions**) by *ID*.
Everything that can be resolved by :php:meth:`Infocyph\\InterMix\\DI\\Container::get`
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

   // 💠 autowired factory closure
   $def->bind('uuid', fn () => bin2hex(random_bytes(16)));

You may chain calls – the manager is **fluent** and ``->end()`` brings you back to
the container:

.. code-block:: php

   // Using method chaining (fluent interface)
   $c->definitions()
       ->bind('foo', 123)
       ->bind('bar', 456)
       ->lock();  // lock the container after definitions are registered

   // Using array access (via ManagerProxy)
   $def = $c->definitions();
   $def['baz'] = fn() => new SomeService();  // Same as bind()
   $service = $def['baz'];  // Same as get()
   $canResolveBaz = isset($def['baz']);  // Same broad check as Container::has()
   $hasBazDefinition = $def->has('baz'); // Explicit registration only

Explicit definitions and resolution state
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``$c->definitions()->has($id)`` when deciding whether application setup may
install a default binding. This checks only definitions and callable resources
that were deliberately registered. An autowireable class, an environment-bound
interface, or an ID that was resolved earlier does not become an explicit
definition.

``$c->has($id)`` remains the broad PSR-style resolvability check. To ask the
separate lifecycle question, use ``$c->isResolved($id)``. It becomes true after
the service resolves successfully at least once and remains true for the life of
that container, including for transient services and after cache or scope state
is cleared.

.. code-block:: php

   if (!$c->definitions()->has(LoggerInterface::class)) {
       $c->bind(LoggerInterface::class, FileLogger::class);
   }

   if ($c->isResolved(DatabaseConnection::class)) {
       // The process has already constructed this service through the container.
   }

Activating unresolved dependencies
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Hosts with modular or deferred service providers can register a global
``onMissing`` callback. InterMix calls it only after the normal definition,
resolved-entry, concrete-class, and active environment-binding checks cannot
resolve an ID:

.. code-block:: php

   $c->onMissing(function (string $id, Container $container): void {
       if ($id === Payments::class) {
           $container->singleton(Payments::class, StripePayments::class);
       }
   });

   $checkout = $c->get(Checkout::class); // nested Payments dependency activates here

Callbacks run in registration order and stop as soon as ``has($id)`` becomes
true. Return values are ignored: a callback must register a normal definition
or environment binding. The resulting service then follows its configured
lifetime, scope, compiled/dynamic resolver path, tracing, and
``onResolving``/``onResolved`` lifecycle hooks.

Activation applies to direct ``get()``, ``make()``, and ``call()`` requests as
well as nested constructor, method, property, and ``#[Inject]`` dependencies.
Failures are not cached, so a later resolution may try the callbacks again.
Recursion is guarded per service ID, allowing one missing service to activate a
different service without permitting an ``A → B → A`` loop. Callback exceptions
propagate unchanged. Register hooks and any definitions they may add before
calling ``lock()``; locking remains strict.

---------------------------------------------------
2.  Direct factories — no reflection or autowiring
---------------------------------------------------

Regular closure definitions are parameter-autowired when injection is enabled.
When dependencies are already explicit, use a direct factory to avoid closure
reflection on every uncached resolution:

.. code-block:: php

   use Infocyph\InterMix\DI\Container;
   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   $c->bindFactory(
       'mailer',
       static fn (Container $container): Mailer => new Mailer(
           $container->get(MailerConfig::class),
       ),
       LifetimeEnum::Singleton,
       tags: ['infrastructure'],
   );

The factory always receives its owning ``Container`` as the first argument. Its
parameters are never inspected or autowired. This contract is the same when
``injection`` is ``true`` or ``false``.

For fluent lifetime selection:

.. code-block:: php

   $c->factory(
       RequestContext::class,
       static fn (Container $container): RequestContext => new RequestContext(
           $container->get('request.id'),
       ),
   )->scoped();

Finish the pending binding with exactly one of ``singleton()``, ``scoped()``, or
``transient()``. Each accepts an optional array of tags.

-----------------------------------------------
3.  Lifetimes (Singleton ⇢ Scoped ⇢ Transient)
-----------------------------------------------

.. code-block:: php

   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   // default = Singleton
   $def->bind('uniq', fn() => new stdClass());                 // same instance forever

   // Transient – fresh each time
   $def->bind('once', fn() => new stdClass(), LifetimeEnum::Transient);

   // Scoped – unique per “scope” key
   $def->bind('req', fn() => new stdClass(), LifetimeEnum::Scoped);

   $obj1 = $c->get('req');
   $c->enterScope('next-request');
   $obj2 = $c->get('req');          // ⚠️ not equal to $obj1
   $c->leaveScope();

Lifetimes apply **equally** to class-string bindings – InterMix transparently converts them
into internal lazy initialisers.

When the graph is finalized through ``ContainerBuilder``, compatible
definitions become generated slots. Ordinary closures and direct factories stay
as explicit dynamic islands because their captured runtime state cannot be
safely converted into PHP source.

-----------------------------------------------
4.  Tags – collect related services
-----------------------------------------------

.. code-block:: php

   $def->bind('L1', fn () => new ListenerA(), tags: ['event']);
   $def->bind('L2', fn () => new ListenerB(), tags: ['event']);

   foreach ($c->findByTag('event') as $id => $listener) {
       $listener->handle();
   }

Use tags for plug-in systems, domain events, command buses, etc.

----------------------------------------------------
5.  Bulk import & sugar syntax
----------------------------------------------------

**Array import**

.. code-block:: php

   $def->addDefinitions([
       'db.host'            => '127.0.0.1',
       LoggerInterface::class => FileLogger::class,   // interface ⇒ concrete
   ]);

**Property / array / invoke sugar** (handy for tests & prototyping) – available on both the
container *and* all manager classes (DefinitionManager, OptionsManager, InvocationManager, RegistrationManager) thanks to the ``ManagerProxy`` trait:

.. code-block:: php

   $c->logger = fn () => new DummyLogger();          // property
   $c['cfg']  = fn () => ['debug' => true];          // array access

   $log = $c->logger;          // magic __get
   $cfg = $c('cfg');           // __invoke

The same trait also proxies container methods via ``__call()`` (for example ``$def->get('foo')`` or ``$def->has('foo')``), while preserving fluent manager chaining.

----------------------------------------------------
6.  Lazy loading — opt-in or opt-out
----------------------------------------------------

Definitions default to **lazy placeholders** *(cheap objects holding a closure)*,
resolved the **first** time you call ``get('service')``.

Toggle globally:

.. code-block:: php

   $c->options()->enableLazyLoading(false);   // future class bindings resolve without placeholders

User-supplied **closures** are **not wrapped** in another deferred object. They
execute when the ID is resolved (for example on first ``get()`` for singleton/scoped,
or every ``get()`` for transient), not at bind-time.

----------------------------------------------------
7.  Environment-aware bindings  (quick reminder)
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
