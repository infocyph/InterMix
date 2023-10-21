.. _di.cache:

==================
Definition Caching
==================

Everytime you execute the library it will compile the definition on-demand basis & In many cases it may consume execution
time & CPU. To mitigate this, InterMix supports definition caching.

For this our library uses `Symphony Cache Library <https://symfony.com/doc/current/components/cache.html>`__. Check the link
for more details.

InterMix have 2 methods dedicated this cache mechanism.

enableDefinitionCache(CacheInterface $cache)
--------------------------------------------

Execute this function to enable definition caching. As it uses `Symphony Cache Library <https://symfony.com/doc/current/components/cache.html>`__
you can pass any Adapter from this library.

.. code:: php

   use Symfony\Component\Cache\Adapter\FilesystemAdapter;

   $cache = new FilesystemAdapter();
   $container->enableDefinitionCache($cache);

cacheAllDefinitions(bool $forceClearFirst = false)
--------------------------------------------------

Set this in a command to pre cache all the definitions. Pass `true` as parameter to force the re-cache. Must be executed
after setting `enableDefinitionCache()`

.. code:: php

    $container->cacheAllDefinitions();
