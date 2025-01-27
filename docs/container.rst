.. _container:

================================
Dependency Injection (Container)
================================

This section covers how to use the **InterMix** DI (Dependency Injection) container, why it helps you
achieve simpler, decoupled architecture, and how to configure it for advanced needs
(method/property attributes, environment-based overrides, caching, etc.).

.. toctree::
    :titlesonly:
    :hidden:

    di/understanding
    di/usage
    di/attribute
    di/cache
    di/flow

-------------------------------
Use Dependency Injection (Intro)
-------------------------------

Below is a simple code snippet that **does** dependency injection *without* relying heavily on any
external container. Notice that one class **accepts** its dependency via the constructor:

.. code-block:: php

   class MyInjectableClass
   {
       public function somethingHere($doIt, $doThat)
       {
           // do something
       }
   }

   class MyAccessorClass
   {
       private $injectable;

       public function __construct(MyInjectableClass $injectable)
       {
           $this->injectable = $injectable;
       }

       public function set($it, $that)
       {
           // Doing something
           $this->injectable->somethingHere($it, $that);
       }
   }

`MyAccessorClass` depends on `MyInjectableClass`—this is **dependency injection**.
However, you manually create these objects:

.. code-block:: php

   $mic = new MyInjectableClass();
   $mac = new MyAccessorClass($mic);

**Enter** InterMix: The container can automate this creation/wiring.

------------------
Creating the Container
------------------

You can create a **container** using:

.. code-block:: php

   use function Infocyph\InterMix\container;

   $container = container(); // recommended short-hand
   // or
   $container = \Infocyph\InterMix\DI\Container::instance();

Then set up options or register classes:

.. code-block:: php

   $container->options()
       ->setOptions(
           injection: true,
           methodAttributes: true,
           propertyAttributes: true
       )
       ->end();

   $container->registration()
       ->registerClass(MyInjectableClass::class)
       ->end();

   // locking if you want:
   $container->lock();

Afterwards, to get your classes:

.. code-block:: php

   $mac = $container->get(MyAccessorClass::class);

This triggers “**autowiring**” (reflection-based). The container sees that
`MyAccessorClass` needs `MyInjectableClass`, so it builds `MyInjectableClass` first,
then passes it to `MyAccessorClass`.

**Without** the container, you must manually create `MyInjectableClass` and pass it.
With the container, everything is resolved automatically using reflection, caching,
and environment-based overrides if configured.

-------------------
Further Exploration
-------------------


- :ref:`di.understanding` — High-level overview of DI principles
- :ref:`di.usage`         — Detailed usage of InterMix container
- :ref:`di.attribute`     — How to do property and method injection with `#[Infuse(...)]`
- :ref:`di.cache`         — Definition caching for performance
- :ref:`di.flow`          — Internal flow, lazy loading, concurrency notes

We recommend starting with :ref:`di.usage` to see how to register definitions, manage options,
and retrieve your services (e.g. `get(MyAccessorClass::class)`).
Then explore the other pages as needed for advanced features.
