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
* **Disable autowiring** entirely ( `injection:false` ) and describe everything up-front.

The **RegistrationManager** (which utilizes the `ManagerProxy` trait) provides a **fluent** interface for class registration. You can also use array access for a more concise syntax. Finish with ``->end()`` to return to the container.

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
       ]);  // Returns to container

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
  :php:`#[Infuse]` attributes.
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

1. Builds `EmailService`
2. Injects the supplied parameters (plus Infuse fallbacks)
3. Executes `setConfig()`
4. Stores/returns the **configured instance**

**Tip** | You can omit ``$args`` to rely solely on `#[Infuse]` in the
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
2. `#[Infuse]` on the property (if propertyAttributes = true)
3. Do nothing (property remains untouched)

------------------------------------------------------------------
4 · import( ServiceProviderInterface::class )
------------------------------------------------------------------

Service providers encapsulate a **bundle of definitions / registrations**.

.. code-block:: php

   final class FrameworkProvider implements ServiceProviderInterface
   {
       public function register(Container $c): void
       {
           $c->definitions()->bind(LoggerInterface::class, FileLogger::class);
           $c->registration()->registerClass(HttpKernel::class);
       }
   }

   // bootstrap
   $c->registration()->import(FrameworkProvider::class);

Providers are perfect for *modules*, *packages* or *feature toggles*.

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

+----------------------------+----------------------------------------+
| **Call**                   | **Purpose**                            |
+============================+========================================+
| ``registerClass()``        | Constructor wiring                     |
+----------------------------+----------------------------------------+
| ``registerMethod()``       | Post-construction bootstrap            |
+----------------------------+----------------------------------------+
| ``registerProperty()``     | Field overrides (private/static OK)    |
+----------------------------+----------------------------------------+
| ``import()``               | Bulk registration via provider class   |
+----------------------------+----------------------------------------+

See also : :ref:`di.definitions` for **service IDs** and :ref:`di.options`
to fine-tune autowiring, attributes, lazy loading, scopes, etc.
