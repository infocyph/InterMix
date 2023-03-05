.. _container:

=======
Memoize
=======

Memoization is used to speed up computer programs by eliminating the repetitive computation of results and by avoiding
repeated calls to functions that process the same input.

We have 2 functions ``memoize()`` & ``remember()`` which is different from each other on how long the cache will be served.

Example
^^^^^^^

.. code:: php

   class MySpecialClass
   {
       public function __construct()
       {
           // do something here
       }

       public function method1()
       {
           return memoize(function () {
               return microtime(true);
           });
       }

       public function method2()
       {
           return remember($this, function () {
               return microtime(true);
           });
       }

       public function method3()
       {
           return [
               $this->method1(),
               $this->method2()
           ];
       }
   }

Functions
^^^^^^^^^

memoize()
---------

Just pass in a Closure or any callable on first parameter. In 2nd parameter you should pass parameters if the callable require any
parameter. (check example) It doesn't matter how many time you call it will always return the same result.

.. code:: php
    (new MySpecialClass())->method1()
    $classInstance->method1()

remember()
----------

Almost same as previous function but it takes class object (``$this`` or any other class instance) as first parameter &
2 more as parameter with same signature. The difference is, If, any time your class object is garbage collected or destroyed
the memory will be gone, automatically. It is memory safe due to the fact that, it removes the data when it no
longer needed.