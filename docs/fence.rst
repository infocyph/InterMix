.. _container:

==========================================
Fence (Class Initialization Barrier)
==========================================

**Fence** is a collection of traits that, when included and invoked through specific methods, apply barriers and constraints to class initialization.

.. note::
   Fence does not restrict `new` initialization directly. Instead, it provides controlled initialization methods. Check the examples below for details.

Key Features
------------

1. **Singleton (`Single`):** Ensures only one instance of the class is created.
2. **Multiton (`Multi`):** Allows multiple instances, each identified by a unique key.
3. **Limited Multiton (`Limit`):** Like `Multi`, but restricts the total number of instances.
4. **Requirement Checking (`Common`):** Ensures required PHP extensions or classes are available before creating an instance.
5. **Instance Management:** Includes utility methods like `clearInstances` or `setLimit` for enhanced lifecycle management.

--------------
Basic Usage
--------------

.. code-block:: php

   use Infocyph\InterMix\Fence\Single;
   use Infocyph\InterMix\Fence\Limit;
   use Infocyph\InterMix\Fence\Multi;

   class Singleton {
       use Single;
   }

   class Limiton {
       use Limit;
   }

   class Multiton {
       use Multi;
   }
--------------
Initialization Methods
--------------

Instead of using `new`, you must initialize classes with specific methods provided by the traits.

.. code-block:: php

   // Singleton: Returns the same instance every time.
   $sgi = Singleton::instance();

   // Multiton: Returns an instance identified by a key. Same key returns the same instance.
   $mgi = Multiton::instance('myInstance');

   // Limiton: Works like Multiton but enforces a limit on the total number of instances.
   $lgi = Limiton::instance('instanceName');
   $lgi->setLimit(5); // Update the instance limit.

--------------
Applying Requirements
--------------

The `instance` method in **Single**, **Multi**, and **Limit** accepts an optional parameter to define initialization requirements.

.. code-block:: php

   $sgi = Singleton::instance([
       'extensions' => [ // Required PHP extensions.
           'curl',
           'mbstring',
       ],
       'classes' => [ // Required PHP classes (with namespace).
           'Directory',
           'IteratorIterator',
       ],
   ]);

If the requirements are not met, an exception is thrown with a detailed message.

.. code-block:: php

   // Example Exception:
   // Missing extensions: mbstring
   // Missing classes: IteratorIterator

--------------
Logging
--------------

Fence includes built-in support for logging constraint checks. This can be extended to use custom logging solutions.

.. code-block:: php

   // Example: Log a message during constraint validation.
   Singleton::log('Validation started for Singleton initialization.');

--------------
Instance Management
--------------

- Clearing Instances

Instances created through **Multi** and **Limit** can be cleared to reset the class state.

.. code-block:: php

   // Clear all Multiton instances.
   Multiton::clearInstances();

   // Clear Singleton instance.
   Singleton::clearInstance();

- Retrieving Instances

You can retrieve all active instances created by **Multi**.

.. code-block:: php

   $allInstances = Multiton::getInstances();
   print_r($allInstances);

- Updating Limits (for Limit Trait)

You can dynamically adjust the instance creation limit.

.. code-block:: php

   Limiton::setLimit(10); // Set the limit to 10 instances.

Conclusion
----------

The **Fence** traits provide a flexible and extensible way to manage class instantiation, enforce initialization
constraints, and streamline instance management. By leveraging these traits, you can ensure stricter control over object creation in your application.
