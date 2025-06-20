.. _di.attribute:

===================
Attribute Injection
===================

InterMix supports **PHP 8+ native attributes** for expressive, declarative injection.
The system now supports **both built-in and custom attributes**, resolved automatically.

-------------
Built-in Tags
-------------

The core attribute is:

* ``#[Infuse]`` – canonical attribute

Two exact **aliases** are also available:

* ``#[Autowire]`` – familiar to Spring/Java developers
* ``#[Inject]`` – common in many DI frameworks

All three behave **identically**, supporting:

* Injection by **type hint**
* Injection by **container key**
* Injection via **global function**

-------------
Quick Syntax
-------------

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\{Infuse, Autowire, Inject};

   class Service {
       #[Infuse] private LoggerInterface $logger;               // by type
       #[Autowire('cfg.debug')] private bool $debug;            // by container key
       #[Inject(strtotime: '+1 day')] private int $expires;     // via global function
   }

   class App {
       #[Infuse(user: 'admin')]  // method‑level fallback
       public function boot(
           #[Inject('cfg.env')] string $env   // parameter-level override
       ) {}
   }

--------------------
Custom Attribute Support
--------------------

You can now define **your own attribute classes** and plug in a custom resolver.

.. code-block:: php

   #[Attribute(Attribute::TARGET_PROPERTY)]
   class UpperCase {
       public function __construct(public string $text) {}
   }

Then register a resolver:

.. code-block:: php

   $c->attributeRegistry()->register(
       UpperCase::class,
       fn (UpperCase $attr) => strtoupper($attr->text)
   );

Now use it in your class:

.. code-block:: php

   class Banner {
       #[UpperCase('hello world')]
       public string $title;
   }

   echo $c->get(Banner::class)->title;
   // Outputs: "HELLO WORLD"

-------------
Method Injection
-------------

### Inject individual parameters:

.. code-block:: php

   class Mailer {
       public function send(
           #[Infuse('cfg.smtp')] array $config,
           #[Inject] LoggerInterface $log
       ) {}
   }

### Inject via full-method fallback:

.. code-block:: php

   class Worker {
       #[Autowire(retries: 2, delay: 5)]
       public function execute(int $retries, int $delay) {}
   }

.. note::
   Parameters passed via `call()` or `registerMethod()` override attribute values.

--------------------------
Property Injection Support
--------------------------

When ``propertyAttributes`` is enabled:

.. code-block:: php

   class Controller {
       #[Infuse] private Request $request;                    // by type
       #[Autowire('cfg.csrf_token')] private string $csrf;   // by key
       #[UpperCase('admin')] private string $role;           // custom
   }

Injection happens **after** constructor resolution.
If a value is already set via `registerProperty()`, it takes precedence.

--------------------------
How to Enable Attribute Support
--------------------------

Attribute support is disabled by default. Enable it selectively:

.. code-block:: php

   $c->options()->setOptions(
       injection: true,            // enable container auto-wiring
       methodAttributes: true,     // allow method & parameter #[Infuse]
       propertyAttributes: true    // allow property #[Infuse]
   );

.. note::
   You can enable only one (e.g., `propertyAttributes`) for scoped usage.

-------------------------------
Resolution Priority (high → low)
-------------------------------

1. `registerClass()` / `registerMethod()` / `registerProperty()`
2. Supplied arguments (e.g., via `call()`, `make()`)
3. Container `definitions()`
4. `#[Infuse]`, `#[Autowire]`, `#[Inject]`
5. Custom attribute via registered `AttributeResolver`

-----------------------
Advanced Usage Examples
-----------------------

### Injecting scalar config:

.. code-block:: php

   class Analytics {
       #[Inject('cfg.api_key')] private string $apiKey;
   }

### Inject via global callable:

.. code-block:: php

   class Session {
       #[Infuse('uuid_create')] private string $sessionId;
   }

### Inject using a registered custom attribute:

.. code-block:: php

   class Tagline {
       #[UpperCase('power of code')] public string $text;
   }

----------------
Testing Tip
----------------

InterMix provides full support for custom attribute resolution and traceable output.

Enable debug tracing to inspect injection path:

.. code-block:: php

   $c->options()->enableDebugTracing(true);
   $c->get(MyService::class);
   print_r($c->debug(MyService::class));

----------------
Summary
----------------

+ Three equivalent built-in tags: ``Infuse``, ``Autowire``, ``Inject``
+ Register your **own attributes** with `attributeRegistry()`
+ Attribute injection is supported on properties, parameters, and methods
+ Resolution supports type hints, container IDs, and global functions
+ Declarative, testable, traceable

Next up → :ref:`di.lifetimes`
