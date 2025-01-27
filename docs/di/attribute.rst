.. _di.attribute:

==========
Attributes
==========

InterMix supports **property** and **method** injection via the ``Infuse`` attribute
(:php:class:`Infocyph\\InterMix\\DI\\Attribute\\Infuse`). This lets you annotate class
properties or method parameters so the container can **inject** values or definitions
automatically.

------------

Prerequisites
------------

- **Method Attributes**:
  Set ``methodAttributes = true`` in :php:meth:`Infocyph\\InterMix\\DI\\Managers\\OptionsManager.setOptions`.
- **Property Attributes**:
  Set ``propertyAttributes = true`` in :php:meth:`Infocyph\\InterMix\\DI\\Managers\\OptionsManager.setOptions`.

Once enabled, the container checks for the :php:class:`Infocyph\\InterMix\\DI\\Attribute\\Infuse`
attributes and resolves them accordingly during:

- **Method resolution** (constructor or user-registered method).
- **Property resolution** (if property injection is allowed).

-----------------
Method Attributes
-----------------

Method attributes can appear in **two** ways:

1. **On Individual Parameters**:

   .. code-block:: php

       use Infocyph\InterMix\DI\Attribute\Infuse;

       class MyService
       {
           public function example(
               #[Infuse('foo')] string $foo
           ) {
               // 'foo' is looked up in the container definitions or environment overrides
           }
       }

   If no explicit parameter supply is found for ``$foo``, it tries the container definitions
   under ``'foo'``.

2. **On the Entire Method** with key-value pairs:

   .. code-block:: php

       use Infocyph\InterMix\DI\Attribute\Infuse;

       class MyService
       {
           #[Infuse(foo: 'data')]
           public function example(string $foo) {
               // $foo defaults to 'data' if no other supply or definitions override it
           }
       }

   The container merges these attribute-provided values with any user-supplied or
   definition-based parameters.

------------------
Property Attribute
------------------

When **propertyAttributes** is true, InterMix can inject properties via
``#[Infuse(...)]``:

1. **Injecting a Class**:

   .. code-block:: php

       use Infocyph\InterMix\DI\Attribute\Infuse;

       class Example
       {
           #[Infuse]
           private AClass $aClassInstance;
           // The container resolves AClass automatically if injection is enabled.
       }

2. **Injecting a Definition or Function**:

   .. code-block:: php

       use Infocyph\InterMix\DI\Attribute\Infuse;

       class Example
       {
           #[Infuse('db.host')]
           private string $host;
           // 'db.host' is fetched from container definitions

           #[Infuse(strtotime: 'last monday')]
           private int $timestamp;
           // calls strtotime('last monday') and injects the result
       }

**Note**: If you also provided property values via
:php:meth:`Infocyph\\InterMix\\DI\\Managers\\RegistrationManager.registerProperty()`,
that user-supplied data **overrides** the attribute approach.

----

Enabling Attributes
-------------------

Just call:

.. code-block:: php

   $container->options()
       ->setOptions(
           injection: true,
           methodAttributes: true,
           propertyAttributes: true
       );

Now any :php:class:`Infuse` attributes on methods/parameters/properties
are honored when the container builds or calls those classes.

**Tip**: If you only want method injection, set just ``methodAttributes=true``
and leave property as false, or vice versa.
