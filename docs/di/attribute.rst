.. _di.attribute:

===================
Attribute Injection
===================

InterMix supports **PHP 8+ native attributes** for expressive, declarative injection.
The system now supports both **built-in** and **custom attribute resolvers**, fully integrated with the container lifecycle.

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
       #[Infuse(user: 'admin')]  // method-level fallback
       public function boot(
           #[Inject('cfg.env')] string $env   // parameter-level override
       ) {}
   }

--------------------------
Custom Attribute Support
--------------------------

InterMix allows **custom attribute classes** to be registered at runtime, enabling reusable, declarative logic or injection.

.. code-block:: php

   #[Attribute(Attribute::TARGET_PROPERTY)]
   class UpperCase {
       public function __construct(public string $text) {}
   }

Then register a resolver:

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;

   class UpperCaseResolver implements AttributeResolverInterface {
       public function resolve(
           object $attributeInstance,
           Reflector $target,
           Container $container
       ): mixed {
           return strtoupper($attributeInstance->text);
       }
   }

   $c->attributeRegistry()->register(
       UpperCase::class,
       UpperCaseResolver::class
   );

Now use it:

.. code-block:: php

   class Banner {
       #[UpperCase('hello world')]
       public string $title;
   }

   echo $c->get(Banner::class)->title;
   // Outputs: "HELLO WORLD"

.. hint::
    Multiple attributes can be used together — all resolvers will run.
    Only the **first non-null** result is used for injection; others may perform logic.

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

-------------------------------
Resolution Workflow
-------------------------------

For **parameter** and **property** attributes:

1. If the attribute is one of: `Infuse`, `Autowire`, or `Inject`:
   * Only the **first** applicable one is resolved and injected.
2. All other custom attributes:
   * Every registered attribute is **executed in order**.
   * If the resolver returns a **non-null, non-IMStdClass** value, it will be injected (first match only).
   * If no resolver injects anything, but any resolver **handled** the attribute, default resolution is skipped.

This supports:
✅ Flexible decoration
✅ Early injection override
✅ Side-effect-only attributes

--------------------------
How to Enable Attribute Support
--------------------------

Attribute support is disabled by default. Enable it selectively:

.. code-block:: php

   $c->options()->setOptions(
       injection: true,            // enable container auto-wiring
       methodAttributes: true,     // allow method & parameter #[Infuse]
       propertyAttributes: true    // allow property #[Infuse]
   )

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

--------------------------
Advanced Usage Examples
--------------------------

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

### Custom resolver that only runs logic (no injection):

.. code-block:: php

   #[Attribute(Attribute::TARGET_METHOD)]
   class LogCall {
       public function __construct(public string $level = 'info') {}
   }

   class LogCallResolver implements AttributeResolverInterface {
       public function resolve(object $attr, Reflector $target, Container $c): mixed {
           $c->logger()->log($attr->level, "[DI] $target handled.");
           return null; // skip injection, but marks as handled
       }
   }

   // Register & use:
   $c->attributeRegistry()->register(LogCall::class, LogCallResolver::class);

   class Action {
       #[LogCall('debug')]
       public function fire() {}
   }

----------------
Testing Tip
----------------

InterMix provides full support for attribute-based resolution with traceable output.

Enable debug tracing to inspect resolution paths:

.. code-block:: php

   $c->options()->enableDebugTracing(true);
   $c->get(MyService::class);
   print_r($c->debug(MyService::class));

----------------
Summary
----------------

+ Built-in attribute tags: ``Infuse``, ``Autowire``, ``Inject``
+ Custom attributes can be registered via `attributeRegistry()`
+ Supported on properties, method parameters, and entire methods
+ Flexible injection from type-hint, container ID, or global functions
+ Multiple attribute resolvers can be run per target
+ Can be used for resolution **or logic only** (no injection)
+ Fully traceable and testable resolution lifecycle

Next up → :ref:`di.lifetimes`
