.. _di.attribute:

===================
Attribute Injection
===================

InterMix supports **PHP 8+ native attributes** for expressive, declarative
injection.
Two families exist:

* **Built-in** tags shipped with InterMix (`Infuse` / `Autowire` / `Inject`)
* **Custom** attributes you register at runtime through
  :py:meth:`Infocyph.InterMix.DI.Attribute.AttributeRegistry.register`

-------------------------------------------------
Built-in Tags (Infuse / Autowire / Inject)
-------------------------------------------------

* ``#[Infuse]`` – canonical
* ``#[Autowire]`` – Spring-style alias
* ``#[Inject]`` – common DI alias

They are **identical** and can inject via :

* **Type-hint** (class / interface)
* **Container key** (`'cfg.debug'`, `'db.host'`, …)
* **Global callable** (e.g. ``#[Inject(strtotime: 'next monday')]``)

-------------------------------------------------
Quick syntax
-------------------------------------------------

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\{Infuse, Autowire, Inject};

   class Service {
       #[Infuse]                        private LoggerInterface $logger; // by type
       #[Autowire('cfg.debug')]         private bool $debug;             // by key
       #[Inject(strtotime: '+1 day')]   private int  $expires;           // via function
   }

   class App {
       #[Infuse(user: 'admin')]       // method-level default
       public function boot(
           #[Inject('cfg.env')] string $env   // parameter-level override
       ) {}
   }

-------------------------------------------------
Custom Attribute Support
-------------------------------------------------

Create any attribute & a resolver that implements
:pyclass:`Infocyph.InterMix.DI.Attribute.AttributeResolverInterface`.

.. code-block:: php

   #[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
   class UpperCase {
       public function __construct(public string $text) {}
   }

   use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;

   class UpperCaseResolver implements AttributeResolverInterface {
       public function resolve(
           object     $attribute,
           Reflector  $target,
           Container  $c
       ): mixed {
           return strtoupper($attribute->text);
       }
   }

   // Register once during bootstrap
   $c->attributeRegistry()->register(
       UpperCase::class,
       UpperCaseResolver::class
   );

Usage:

.. code-block:: php

   class Banner {
       #[UpperCase('hello world')]
       public string $title;
   }

   echo $c->get(Banner::class)->title;    // HELLO WORLD

.. hint::

   *Multiple* attributes may decorate the **same** target.
   InterMix calls each registered resolver in discovery order; the **first**
   non-null, non-``IMStdClass`` result becomes the injected value.
   Later resolvers can still run side-effect logic even if they don’t inject.

-------------------------------------------------
Method & Parameter Injection
-------------------------------------------------

.. code-block:: php

   class Mailer {
       public function send(
           #[Infuse('cfg.smtp')] array $config,
           #[Inject]  LoggerInterface $log
       ) {}
   }

Whole-method defaults:

.. code-block:: php

   class Worker {
       #[Autowire(retries: 2, delay: 5)]
       public function execute(int $retries, int $delay) {}
   }

*Arguments provided* via :php:meth:`Container::call`,
:py:meth:`registerMethod()` or explicit arrays always **override** attributes.

-------------------------------------------------
Property Injection
-------------------------------------------------

Enable with ``propertyAttributes: true``:

.. code-block:: php

   class Controller {
       #[Infuse]        private Request $request;          // by type
       #[Autowire('cfg.csrf')] private string $csrf;       // by key
       #[UpperCase('admin')]  private string $role;        // custom
   }

Properties are injected *after* construction.
Values set via :py:meth:`registerProperty()` win over attributes.

-------------------------------------------------
Resolution Workflow
-------------------------------------------------

#. **Built-in tag** (`Infuse` / `Autowire` / `Inject`) – first match wins
#. **Custom attributes** – executed in registration order:

   * if a resolver returns **non-null & not `IMStdClass`** → injected
   * if resolver returns `null` or `IMStdClass` → treated as “logic-only”

-------------------------------------------------
Enabling Attributes
-------------------------------------------------

.. code-block:: php

   $c->options()->setOptions(
       injection:           true,
       methodAttributes:    true,   // enable #[Infuse] on params / methods
       propertyAttributes:  true    // enable #[Infuse] on properties
   );

You may enable only one flag to limit scope.

-------------------------------------------------
Resolution Priority (high → low)
-------------------------------------------------

1. ``registerClass()`` / ``registerMethod()`` / ``registerProperty()``
2. Supplied args (`call()`, `make()`, etc.)
3. ``definitions()`` map
4. Built-in tags (Infuse / Autowire / Inject)
5. Custom attributes via **AttributeRegistry**

-------------------------------------------------
Examples
-------------------------------------------------

Inject scalar config:

.. code-block:: php

   class Analytics {
       #[Inject('cfg.api_key')] private string $apiKey;
   }

Global callable:

.. code-block:: php

   class Session {
       #[Infuse('uuid_create')] private string $sessionId;
   }

Logic-only attribute (no injection):

.. code-block:: php

   #[Attribute(Attribute::TARGET_METHOD)]
   class LogCall {
       public function __construct(public string $level = 'info') {}
   }

   class LogCallResolver implements AttributeResolverInterface {
       public function resolve(object $attr, Reflector $target, Container $c): mixed {
           $c->logger()->log($attr->level, "[DI] $target handled");
           return null;       // no injection, marks as handled
       }
   }

-------------------------------------------------
Debugging
-------------------------------------------------

.. code-block:: php

   $c->options()->enableDebugTracing(true);
   $c->get(MyService::class);
   print_r($c->debug(MyService::class));

-------------------------------------------------
Summary
-------------------------------------------------

* Built-in tags: **Infuse / Autowire / Inject**
* Register unlimited **custom** attributes with resolvers
* Works on properties, parameters, or whole methods
* First non-null result wins; others may perform side-effects only
* Fully traceable with ``enableDebugTracing()``

Next → :ref:`di.lifetimes`
