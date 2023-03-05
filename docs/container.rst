.. _container:

================================
Dependency Injection (Container)
================================

.. toctree::
    :titlesonly:
    :hidden:

    di/understanding
    di/usage
    di/init
    di/sum-up

Using the library is pretty straight-forward.

**Use dependency injection**

Let’s write code using dependency injection without thinking any DI:

.. code-block:: php

   class MyInjectableClass
   {
       public function somethingHere($doIt, $doThat)
       {
           // doing something here
       }
   }

.. code-block:: php

   class MyAccessorClass
   {
       private $injectable;

       public function __construct(MyInjectableClass $injectable)
       {
           $this->mailer = $mailer;
       }

       public function set($it, $that)
       {
           // Doing something
           // ....
           $this->injectable->somethingHere($it, $that);
       }
   }

As we can see, the ``MyAccessorClass`` takes the ``MyInjectableClass`` as a constructor parameter & this is dependency injection!

**Create the container**

Creating the container is as easy as it can be,

.. code-block:: php

   $container = new AbmmHasan\InterMix\container();

Then simply register or setOptions as your requirements:

.. code-block:: php

   $container->setOptions(...)
   $container->register...(...);

**Create the objects**

Without dependency injection

.. code-block:: php

   $mic = new MyInjectableClass();
   $mac = new MyAccessorClass($mic);

With our library we can just do:

.. code-block:: php

   $mac = $container->get(MyAccessorClass::class);

The container uses a technique called **autowiring**. This will scan the code and see what
are the parameters needed in the constructors. Our container uses `PHP’s Reflection
classes <http://php.net/manual/en/book.reflection.php>`__ which is pretty standard: Laravel, Zend Framework and
many other containers do the same. Performance wise, such information is read once and then
cached, it has (almost) no impact.
