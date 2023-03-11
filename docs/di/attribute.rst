.. _di.attribute:

==========
Attribute
==========

Using **attribute** we can pass data into property or method if related options are enabled. For this there is some pre-requisite as given below:

For method attribute,

* ``methodAttributes`` parameter in ``setOptions()`` should be set to true
* On method or arguments, attributes should be marked using ``AbmmHasan\InterMix\DI\Attribute\Infuse()`` class

For property attribute

* ``propertyAttributes`` parameter in ``setOptions()`` should be set to true
* Attributes should be marked using ``AbmmHasan\InterMix\DI\Attribute\Infuse()`` class

Method attribute
----------------

In case of method attribute, assignment is possible in 2 ways:

* on argument

.. code:: php

    function example(#[Infuse('foo')] string $foo) {}

* on method (with DocBlock)

.. code:: php

    #[Infuse('foo')]
    function example(string $foo) {}

As type hinting on arguments works directly (also better traceable signature) in case of method, it won't work via Attribute.
What you can do is, enter a function call or definition alias.

Property attribute
------------------

In case of property 2 possible ways:

* Inject class

.. code:: php

    #[Infuse]
    private AClass $aClassInstance;

* Same as Method attribute

.. code:: php

    #[Infuse(config: 'db.host')]
    private string $aClassInstance;