.. _container:

=======
Memoize
=======

Memoization is a technique used to speed up programs by avoiding repeated computations of the same results. This is achieved by caching the results of function calls and returning the cached value when the same inputs occur.

In the **Infocyph\InterMix** library, there are two main memoization functions: ``memoize()`` and ``remember()``. The primary difference between the two lies in **how long the cached data persists**.

Let's explore their usage with examples:

Example Usage
^^^^^^^^^^^^^

.. code:: php

   use Infocyph\InterMix\Memoize\Cache;
   use Infocyph\InterMix\Memoize\WeakCache;

   class MySpecialClass
   {
       public function __construct()
       {
           // Initialization logic here
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
               $this->method2(),
           ];
       }
   }

Functions
^^^^^^^^^

memoize()
---------

The ``memoize()`` function caches the result of a callable in a persistent cache. It accepts the following parameters:

- **callable**: The function or method to memoize.
- **parameters** (optional): An array of arguments to pass to the callable.
- **ttl** (optional): The time-to-live for the cache in seconds. Defaults to no expiration.
- **forceRefresh** (optional): If set to true, bypasses the cache and forces recomputation.

No matter how many times you call the memoized function, it will return the same result unless the cache expires or is explicitly refreshed.

.. code:: php

    (new MySpecialClass())->method1(); // 1st time, computed
    (new MySpecialClass())->method1(); // 2nd time, cached result

You can also retrieve the ``Cache`` instance directly:

.. code:: php

    $cache = memoize();
    $cache->flush(); // Clear all cached values

remember()
----------

The ``remember()`` function works similarly to ``memoize()``, but it uses a memory-safe, ephemeral cache backed by ``WeakCache``. The key difference is that cache entries are associated with a class instance (e.g., ``$this``) and will be cleared automatically when the class instance is garbage collected.

Parameters:
- **classObject**: The object instance to associate with the cache.
- **callable**: The function or method to memoize.
- **parameters** (optional): An array of arguments to pass to the callable.

This is useful when you need caching within the lifespan of a specific object but want the cache to be cleared automatically when the object is no longer in use.

.. code:: php

    (new MySpecialClass())->method2(); // 1st time, computed
    (new MySpecialClass())->method2(); // 2nd time, recomputed because object is new

Key Differences
^^^^^^^^^^^^^^^^

1. **memoize()**: Cache persists globally and across instances until explicitly cleared or expired (if TTL is used).
2. **remember()**: Cache is tied to the lifecycle of the class instance and cleared automatically when the instance is destroyed.

Advanced Features
^^^^^^^^^^^^^^^^^

Both ``memoize()`` and ``remember()`` leverage powerful caching features:

- **Custom Drivers**:
  For persistent caching with ``memoize()``, you can set a custom caching driver via the ``setCacheDriver()`` method in the ``Cache`` class. For example, using an in-memory cache:

  .. code:: php

      use Symfony\Component\Cache\Adapter\ArrayAdapter;

      $cache = memoize();
      $cache->setCacheDriver(new ArrayAdapter());

- **Namespaces**:
  To avoid key collisions, you can set a namespace for cache keys:

  .. code:: php

      $cache = memoize();
      $cache->setNamespace('my-app');

- **Statistics**:
  The ``WeakCache`` class tracks cache hits and misses. You can retrieve statistics with:

  .. code:: php

      $weakCache = remember();
      print_r($weakCache->getStatistics()); // ['hits' => 2, 'misses' => 1, 'total' => 3]

- **Ephemeral Caching**:
  ``remember()`` is a memory-safe, ephemeral cache. This means that each cache entry is associated with a class instance and will be cleared automatically when the class instance is garbage collected.

- **Garbage Collection**:
  When the class instance is garbage collected, ``remember()`` will automatically clear the cache entries associated with it.
