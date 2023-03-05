.. _container:

====================================
Fence (Class initialization barrier)
====================================

Well, **Fence** is collection of some traits that if included and called via some specific method, will apply barrier
to it.

.. note::
    It doesn't do anything on ``new`` initialization just check below examples for details.

Lets understand with example
============================

.. code:: php

   class Singleton {
    use Single;
   }
   class Limiton {
    use Limit;
   }
   class Multiton {
    use Multi;
   }

Instead of initializing with ``new``, we have to initialize differently
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. code:: php

   // Singleton will return same instance over and over, once defined
   $sgi = Singleton::instance();

   // Multiton will return instance by given key name (1st parameter). Same instance will be return for same name
   $mgi = Multiton::instance('instanceName');

   // Limiton will give instance like Multiton except it will create instance upto a limit count (default 2)
   $lgi = Limiton::instance('instanceName');
   $lgi->setLimit(5); // changing limit count

Well I wanna apply requirements in other example as well
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

No problem that covered as well! ``instance`` method in **Single, Multi,
Limit** accepts one (more) parameter where you can send the requirement
array as well.

.. code:: php

   $sgi = Singleton::instance([
       'extensions' => [ // Extension list
           'curl',
           'mbstring'
       ],
       'classes' => [ // Class names (with namespaces)
           'Directory',
           'IteratorIterator'
       ]
   ]);