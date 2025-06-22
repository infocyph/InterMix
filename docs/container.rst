.. _container:

================================
Dependency Injection (Container)
================================

.. admonition:: TL;DR
   :class: tip

   *Create → Register → Resolve*

   .. code-block:: php

      use function Infocyph\InterMix\container;

      $c = container();                       // ① create
      $c->definitions()->bind('answer', 42);  // ② register
      echo $c->get('answer');                 // ③ resolve  → 42

-----------------------------------
Why bother with a DI-Container?
-----------------------------------

*Manual wiring* is fine—until the tenth place you instantiate the same class,
or the day you must **swap** an implementation in *production* only.
InterMix automates construction, honours interfaces, supports
environment-specific overrides and gives you sugar for 1-liners.

------------------------------------------------
15-second “Hello World” with constructor autowire
------------------------------------------------

.. code-block:: php

   class Greeter
   {
       public function __construct(private Clock $clock) {}

       public function greet(string $name): string
       {
           return sprintf(
               'Hello %s — %s',
               $name,
               $this->clock->now()->format('c')
           );
       }
   }

   interface Clock { public function now(): DateTimeImmutable; }
   class SystemClock implements Clock
   {
       public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
   }

.. code-block:: php

   $c = container()
       ->definitions()->bind(Clock::class, SystemClock::class)->end();

   echo $c->get(Greeter::class)->greet('Alice');
   // → “Hello Alice — 2025-06-18T12:34:56+00:00”

--------------------------------
Creating & naming containers
--------------------------------

Every call to :php:`container('alias')` (or
:php:`Container::instance('alias')`) returns an **isolated** registry.
Use distinct aliases for tests, CLI workers, micro-modules, etc.

---------------------------------------------
Modifying behaviour with ``options()->setOptions()``
---------------------------------------------

+ **injection** – reflection autowiring engine
+ **methodAttributes / propertyAttributes** – enable **`#[Infuse]`**
+ **defaultMethod** – method to call when none supplied
+ **lazyLoading** – defer heavy construction until first use

.. code-block:: php

   $c->options()->setOptions(
       injection: true,
       methodAttributes: true,
       propertyAttributes: true,
       defaultMethod: 'handle'
   );

--------------------------------------------------------
Common quick patterns (copy-paste as you learn the rest)
--------------------------------------------------------

*Register a class with predefined constructor args* ::

   $c->registration()->registerClass(Db::class, [
       'dsn' => 'mysql://root@localhost/db',
       'flags' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
   ]);

*Register a bootstrap method* ::

   $c->registration()->registerMethod(App::class, 'boot');

*Tag & iterate* ::

   $c->definitions()->bind('L1', fn()=>new ListenerA, tags:['event']);
   foreach ($c->findByTag('event') as $id => $listener) {
       $listener()->handle();
   }

*Scope isolation* ::

   $c->getRepository()->setScope('request-123');
   // scoped services now unique to this request

-----------
Dive in Details
-----------

Dive into the detailed sub-chapters. Happy mixing!  Questions?  Open an issue or drop by the discussion board.

.. toctree::
    :titlesonly:
    :hidden:

    di/overview
    di/quickstart
    di/understanding
    di/definitions
    di/registration
    di/options
    di/invocation
    di/invoker
    di/attribute
    di/lifetimes
    di/scopes
    di/lazy_loading
    di/tagging
    di/environment
    di/cache
    di/preload
    di/debug_tracing
    di/cheat_sheet
    di/best_practices
