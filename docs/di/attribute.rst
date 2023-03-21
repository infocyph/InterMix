.. _di.attribute:

==========
Attribute
==========

Using **attribute** we can pass data into property or method if related options are enabled. For this there is
some pre-requisite as given below:

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

    // foo will be resolved into $foo
    function example(#[Infuse('foo')] string $foo) {}

* on method (with DocBlock)

.. code:: php

    // foo will be resolved into $foo
    #[Infuse(foo: 'data')]
    function example(string $foo) {}

Property attribute
------------------

In case of property 2 possible ways:

* Inject class

.. code:: php

    // AClass will be resolved and injected
    #[Infuse]
    private AClass $aClassInstance;

* Same as Method attribute

.. code:: php

    // definition will be injected (db.host)
    #[Infuse('db.host')]
    private string $aClassInstance;