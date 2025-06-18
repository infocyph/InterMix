.. _di.attribute:

===================
Attribute Injection
===================

InterMix supports **PHP 8+ native attributes** for expressive, declarative injection.
The main attribute is:

* ``#[Infuse]`` – canonical

and it has two exact **aliases** for convenience or preference:

* ``#[Autowire]`` – familiar to Spring/Java developers
* ``#[Inject]`` – common in DI ecosystems

All three work **identically**. Use what suits your project style.

-------------
Quick Syntax
-------------

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\{Infuse, Autowire, Inject};

   class Service {
       #[Infuse] private LoggerInterface $logger;               // inject by type
       #[Autowire('cfg.debug')] private bool $debug;            // inject by definition key
       #[Inject(strtotime: '+1 day')] private int $expires;     // inject via function
   }

   class App {
       #[Infuse(user: 'admin')]  // method‑level fallback
       public function boot(
           #[Inject('cfg.env')] string $env   // parameter-level override
       ) {}
   }

---------------
What It Supports
---------------

Attributes can inject values using:

+ Definition **keys** (e.g., `'cfg.debug'`)
+ Fully-qualified **class or interface names**
+ Global **functions** (e.g., `strtotime`, `uuid_create`, etc.)

This makes it flexible for injecting both services and scalar config.

----------------
How to Enable
----------------

By default, attribute parsing is **disabled** to avoid surprises.

Enable it like so:

.. code-block:: php

   $c->options()->setOptions(
       injection: true,            // enable auto-wiring engine
       methodAttributes: true,     // enable parameter/method #[Infuse]
       propertyAttributes: true    // enable property #[Infuse]
   );

.. note::
   You may enable only one (e.g., `propertyAttributes: true`) to scope usage.

-----------------------------------
Method & Parameter Injection
-----------------------------------

### Inject individual parameters:

.. code-block:: php

   class Mailer {
       public function send(
           #[Infuse('cfg.smtp')] array $config,
           #[Inject] LoggerInterface $log
       ) {}
   }

### Inject via whole-method default:

.. code-block:: php

   class Worker {
       #[Autowire(retries: 2, delay: 5)]
       public function execute(int $retries, int $delay) {}
   }

**Note**: Parameters defined directly via call() or registration will override attribute values.

--------------------------
Property Injection Support
--------------------------

When ``propertyAttributes`` is enabled, property injection works like:

.. code-block:: php

   class Controller {
       #[Infuse] private Request $request;                // by type
       #[Autowire('cfg.csrf_token')] private string $csrf; // by definition key
   }

This occurs **after** constructor resolution.

If the same property is configured via `registerProperty()`, the registered value takes precedence.

-------------------------------
Resolution Priority (high → low)
-------------------------------

1. ``registerClass()`` / ``registerMethod()`` / ``registerProperty()``
2. Supplied arguments (e.g., `call()`, `make()`)
3. Container ``definitions()``
4. ``#[Infuse]`` / ``#[Autowire]`` / ``#[Inject]``

------------------------
Advanced Usage Examples
------------------------

### Inject using callable

.. code-block:: php

   class TokenProvider {
       #[Infuse('uuid_create')] private string $token;
   }

### Injecting configuration values

.. code-block:: php

   class Analytics {
       #[Inject('cfg.api_key')] private string $apiKey;
   }

-----------------------
Best Practices
-----------------------

✔ Prefer attributes for **configurable defaults**.
✔ Keep usage **declarative**, not imperative.
✔ Avoid placing secrets directly in attributes — inject via definitions instead.

----------------
Summary
----------------

+ Three equivalent tags: ``Infuse``, ``Autowire``, ``Inject``
+ Supported on class properties, method parameters, and full method signatures
+ Configurable using ``methodAttributes`` and ``propertyAttributes``
+ Resolved from type hints, container keys, or global functions
+ Declarative, testable, and easy to override

Next up → :ref:`di.lifetimes`
