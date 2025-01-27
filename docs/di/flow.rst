.. _di.flow:

=============
Feature Flow
=============

This doc describes InterMix’s resolution flow when you do ``get()``, ``getReturn()``, or ``call()``.
It covers both **autowiring** (reflection-based injection) and **lazy loading**.

-----------------------
Main Resolution Steps
-----------------------

1. **Checking existing**:

   - If the ID is already in ``resolved`` with an **object** or user closure, return it immediately.
   - If stored as a :php:class:`DeferredInitializer` (the “lazy placeholder”),
     call the closure to produce the real instance now.

2. **FunctionReference**:

   - If your ID matches a definition in functionReference, container calls
     ``resolveDefinition($id)`` (which checks caching, environment overrides,
     user closures vs. lazy placeholders, etc.).

3. **Fallback** to auto-resolve a **class**:

   - If the container can reflect the class (when injection is enabled),
     it constructs the class or calls the method.
   - If injection is **disabled**, container uses a simpler approach from
     :php:class:`GenericCall`.

4. **Cached?**
   - If definition caching is **enabled**, the container checks the Symfony cache for
     that ID. If found, returns it. Otherwise, it resolves once and stores the result.

----------------------
User Closure vs. Lazy
----------------------

- **User-Supplied Closure**: If you do:

  .. code-block:: php

     $container->definitions()
         ->bind('myClosure', function() { return new ExpensiveClass(); });

  that closure is **resolved** **immediately** (not a lazy placeholder).
  You get back the actual object or closure. This keeps it consistent with
  the idea that the user explicitly wants to store a real closure as the service.
  If caching is on, that final object is stored in cache.

- **`DeferredInitializer`**: Internal “lazy” placeholder used by InterMix for
  class-based definitions if ``enableLazyLoading(true)`` is set. The container
  saves a :php:class:`DeferredInitializer` instead of the real object until
  you call :php:meth:`get()`. This defers heavy resolution until first usage.

**Hence**:

1. **User closure** => immediate execution
2. **Class or array** => possibly **lazy** if the container is set to lazy
   (and you haven't forced a pre-cache or flagged it otherwise).

----------
Concurrency
----------

InterMix uses :php:class:`ReflectionResource` with a **static** cache. In typical
**PHP-FPM** usage, each request is in a separate process, so concurrency is not an
issue. If you do run in a truly **multi-thread** scenario (like Swoole, ReactPHP, or
pthreads), be aware that **static** reflection caches might need synchronization.
Consider clearing or manually locking the reflection cache if you have advanced concurrency needs.

**In standard multi-process** deployments, no special concurrency measures are needed.
But for rare multi-thread environments in PHP, further concurrency controls or
disabling the reflection cache might be required.
