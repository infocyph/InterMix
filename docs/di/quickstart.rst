.. _di.quickstart:

============
Quick-Start
============

InterMix works **out-of-the-box** – a single dependency via Composer and you’re up
and running.  This page merges the former *Getting Started* notes so you have one
concise reference.

Install
-------

.. code-block:: bash

   composer require infocyph/intermix


1 → Create (or retrieve) a container
------------------------------------

A container is identified by an **alias**.
Each alias is an *isolated* registry of services.

.. code-block:: php

   use function Infocyph\InterMix\container;
   use Infocyph\InterMix\DI\Container;

   $c1 = container();              // default alias “intermix”
   $c2 = container('cli');         // a second, independent container
   // identical:
   $c3 = Container::instance('cli');

Keep aliases short and memorable – tests often use a random alias to isolate state.

2 → Configure behaviour (optional)
----------------------------------

.. code-block:: php

   $c1->options()->setOptions(
       injection: true,          // reflection autowiring engine
       methodAttributes: true,   // honour #[Infuse] on parameters
       propertyAttributes: true, // honour #[Infuse] on properties
       defaultMethod: 'handle'   // fallback method name
   );

**Defaults**

+ *injection* = true
+ *methodAttributes* = false
+ *propertyAttributes* = false
+ *lazyLoading* = true


3 → Register something
----------------------

### Bind an ID to a value / factory

.. code-block:: php

   $c1->definitions()
      ->bind('answer', 42)
      ->bind('now', fn () => new DateTimeImmutable());

### Register a class with constructor parameters

.. code-block:: php

   $c1->registration()->registerClass(PDO::class, [
       'mysql:host=localhost;dbname=test', // DSN
       'root',                             // user
       'secret',                           // password
   ]);

### Import a service provider

.. code-block:: php

   $c1->registration()->import(App\Providers\BusProvider::class);


4 → Resolve
-----------

.. code-block:: php

   echo $c1->get('answer');                 // 42
   echo $c1->get('now')->format('c');       // 2025-06-18T12:34:56+00:00

Autowire a class (constructor injection)::

   class Greeter
   {
       public function __construct(DateTimeImmutable $clock) { $this->clock = $clock; }
       public function hello(string $name): string
       {
           return 'Hi '.$name.' @ '.$this->clock->format('c');
       }
   }

   echo $c1->get(Greeter::class)->hello('Bob');


5 → A taste of attributes
-------------------------

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\Infuse;

   class Mailer
   {
       #[Infuse] private LoggerInterface $logger;
       public function __construct(#[Infuse('cfg.smtp')] string $dsn = 'smtp://localhost') {}
   }

   $c1->definitions()
      ->bind(LoggerInterface::class, DummyLogger::class)
      ->bind('cfg.smtp', 'smtp://mail.prod');

   $mailer = $c1->get(Mailer::class);   // property + parameter injected


6 → Environment swap (prod vs. local)
-------------------------------------

.. code-block:: php

   interface PaymentGateway { public function pay(int $amount): string; }
   class StripeGateway implements PaymentGateway { /* … */ }
   class PaypalGateway implements PaymentGateway { /* … */ }

   $c1->options()
      ->bindInterfaceForEnv('prod',  PaymentGateway::class, StripeGateway::class)
      ->bindInterfaceForEnv('local', PaymentGateway::class, PaypalGateway::class)
      ->setEnvironment('prod');

   $gw = $c1->get(PaymentGateway::class);   // StripeGateway in prod


7 → Lock & ship
---------------

After bootstrap you may **lock** the container to block any further
accidental modifications:

.. code-block:: php

   $c1->lock();


What’s next?
------------

* **Cheat-Sheet** – one-page reference of every call.
* **Definitions / Registration / Options** – deep dives into each manager.
* **Attributes** – everything about ``#[Infuse]``.
* **Debug Tracing** – X-ray a resolution path when things go sideways.

Happy mixing — your clay is ready!
