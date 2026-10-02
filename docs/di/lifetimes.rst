.. _di.lifetimes:

================================================
Service lifetimes
================================================

InterMix supports Singleton, Scoped, and Transient lifetimes.

.. code-block:: php

   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   $builder
       ->autowire(Cache::class, Cache::class, lifetime: LifetimeEnum::Singleton)
       ->autowire(RequestState::class, RequestState::class, lifetime: LifetimeEnum::Scoped)
       ->autowire(Job::class, Job::class, lifetime: LifetimeEnum::Transient);

Singleton
---------

One instance per runtime.

Scoped
------

One instance per active logical scope. Scoped entries cannot be resolved from the root scope. A singleton may not capture a scoped dependency.

Transient
---------

A fresh instance is created for each resolution.

Separate runtimes created from the same finalized builder have separate lifetime stores.
