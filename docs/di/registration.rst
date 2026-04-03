.. _di.registration:

==========================
Registration Manager
==========================

``$c->registration()`` unlocks **class-level metadata** so you can steer
how InterMix builds an object **without** sprinkling the source code with
attributes.

Why register?

* **Add scalars / env values** a constructor would otherwise not receive.
* **Call a bootstrap method** right after instantiation.
* **Override private / static properties** in legacy classes.
* **Disable autowiring** entirely ( ``injection:false`` ) and describe everything up-front.

The **RegistrationManager** (which uses the ``ManagerProxy`` trait) provides a **fluent** interface for class registration. You can also use array access for a more concise syntax.
Because it proxies container access, direct calls such as ``$reg->get(Foo::class)`` and sugar forms like ``$reg('id')`` / ``$reg['id']`` also work.
Finish with ``->end()`` to return to the container.

------------------------------------------------------------------
1 · registerClass( FQCN , array $args = [] )
------------------------------------------------------------------

Pass **positional** or **named** arguments – just like PHP itself.

.. code-block:: php

   // Using method chaining (fluent interface)
   $reg = $c->registration()
       ->registerClass(Db::class, [
           'mysql:host=127.0.0.1;dbname=app',   // position #1
           'root',                               // position #2
           'p@ssw0rd',                           // position #3
           'flags' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], // named
       ]);  // stays on RegistrationManager

   // Using array access (via ManagerProxy)
   $reg[Db::class] = [  // Same as registerClass()
       'mysql:host=127.0.0.1;dbname=app',
       'root',
       'p@ssw0rd',
       'flags' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
   ];
   $db = $c->get(Db::class);

Hints
^^^^^

* Un-listed parameters fall back to **autowiring** (if enabled) or
  ``#[Infuse]`` attributes.
* The registration **overrides** anything the attribute would set for the
  same parameter.

------------------------------------------------------------------
2 · registerMethod( FQCN , string $method , array $args = [] )
------------------------------------------------------------------

Schedules a **post-construction call**.

.. code-block:: php

   // EmailService::setConfig(array $cfg)
   $c->registration()
       ->registerMethod(EmailService::class, 'setConfig', [
           ['smtp' => 'localhost', 'port' => 25],   // first (and only) param
       ]);

When you later do:

.. code-block:: php

   $svc = $c->get(EmailService::class);

the container:

1. Builds ``EmailService``
2. Injects the supplied parameters (plus Infuse fallbacks)
3. Executes ``setConfig()``
4. Stores/returns the **configured instance**

**Tip** | You can omit ``$args`` to rely solely on ``#[Infuse]`` in the
method signature.

------------------------------------------------------------------
3 · registerProperty( FQCN , array $map )
------------------------------------------------------------------

Set *private*, *public*, *static* or *promoted* properties without reflection
gymnastics.

.. code-block:: php

   $c->registration()
       ->registerProperty(Configurable::class, [
           'theme'        => 'dark',
           'staticValue'  => 'GLOBAL',
       ]);

Precedence (highest → lowest):

1. **registerProperty()**
2. ``#[Infuse]`` on the property (if propertyAttributes = true)
3. Do nothing (property remains untouched)

------------------------------------------------------------------
4 · import( ServiceProviderInterface::class )
------------------------------------------------------------------

Service providers encapsulate a **bundle of definitions / registrations**.

When to use a provider
^^^^^^^^^^^^^^^^^^^^^^

* You want to group related bindings into one reusable module.
* You publish a package and need one entry-point for container setup.
* You have feature-specific wiring (mail, queue, payments) you may enable/disable.

Why this pattern helps
^^^^^^^^^^^^^^^^^^^^^^

* Keeps bootstrap code small.
* Reduces repeated registration calls across commands/tests/apps.
* Makes wiring easier to test and version.

.. code-block:: php

   interface LoggerInterface
   {
       public function log(string $message): void;
   }

   final class FileLogger implements LoggerInterface
   {
       public function __construct(private string $path = '/tmp/app.log') {}

       public function log(string $message): void
       {
           file_put_contents($this->path, $message . PHP_EOL, FILE_APPEND);
       }
   }

   final class Notifier
   {
       public function __construct(
           private LoggerInterface $logger,
           private string $channel
       ) {}

       public function send(string $msg): void
       {
           $this->logger->log("[$this->channel] $msg");
       }
   }

   final class FrameworkProvider implements ServiceProviderInterface
   {
       public function register(Container $c): void
       {
           // reusable IDs / factories
           $c->definitions()->bind(LoggerInterface::class, FileLogger::class);
           $c->definitions()->bind('notify.channel', 'ops');

           // class-level constructor params
           $c->registration()->registerClass(Notifier::class, [
               'channel' => $c->get('notify.channel'),
           ]);
       }
   }

   // class-string import
   $c->registration()->import(FrameworkProvider::class);

   // instance import is also supported
   $c->registration()->import(new FrameworkProvider());

   $notifier = $c->get(Notifier::class);
   $notifier->send('deployment finished');

Providers are perfect for *modules*, *packages* or *feature toggles*.

Feature-specific wiring (step-by-step)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Step 1: Define the feature contract and implementations.

.. code-block:: php

   interface PaymentGateway
   {
       public function charge(int $amount): string;
   }

   final class StripeGateway implements PaymentGateway
   {
       public function charge(int $amount): string { return "stripe:$amount"; }
   }

   final class PaypalGateway implements PaymentGateway
   {
       public function charge(int $amount): string { return "paypal:$amount"; }
   }

   final class CheckoutService
   {
       public function __construct(private PaymentGateway $gateway) {}
       public function checkout(int $amount): string { return $this->gateway->charge($amount); }
   }

Step 2: Create one provider per feature variant.

.. code-block:: php

   final class StripePaymentsProvider implements ServiceProviderInterface
   {
       public function register(Container $c): void
       {
           $c->definitions()->bind(PaymentGateway::class, StripeGateway::class);
       }
   }

   final class PaypalPaymentsProvider implements ServiceProviderInterface
   {
       public function register(Container $c): void
       {
           $c->definitions()->bind(PaymentGateway::class, PaypalGateway::class);
       }
   }

Step 3: Choose and import the provider in bootstrap.

.. code-block:: php

   $provider = getenv('PAYMENT_DRIVER') === 'paypal'
       ? PaypalPaymentsProvider::class
       : StripePaymentsProvider::class;

   $c->registration()->import($provider);

Step 4: Resolve the feature service normally.

.. code-block:: php

   $checkout = $c->get(CheckoutService::class);
   echo $checkout->checkout(1200);

This will now act as:

* A feature boundary for payment wiring.
* A single switch point for runtime variant selection.
* A reusable module you can share across apps/tests.

------------------------------------------------------------------
5 · Working in “injection-less” mode
------------------------------------------------------------------

Set ``injection:false`` to **turn off reflection**.
Every class must then be fully described via *registration*:

.. code-block:: php

   $c->options()->setOptions(injection:false);
   $c->registration()
       ->registerClass(PlainOldClass::class, [123])
       ->registerMethod(PlainOldClass::class, 'init', [456])
       ->registerProperty(PlainOldClass::class, ['flag' => true]);

   $val = $c->getReturn(PlainOldClass::class);   // all good 🤝

------------------------------------------------------------------
Cheat-Sheet
------------------------------------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Call
     - Purpose
   * - ``registerClass()``
     - Constructor wiring
   * - ``registerMethod()``
     - Post-construction bootstrap
   * - ``registerProperty()``
     - Field overrides (private/static OK)
   * - ``import()``
     - Bulk registration via provider class

See also : :ref:`di.definitions` for **service IDs** and :ref:`di.options`
to fine-tune autowiring, attributes, lazy loading, scopes, etc.
