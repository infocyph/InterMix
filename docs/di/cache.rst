.. _di.cache:

================================================
Definition caching
================================================

InterMix accepts a host-supplied PSR-6 pool. CacheLayer 4.0.2+ is a supported optional implementation.

Configuration
-------------

.. code-block:: php

   $builder
       ->definitionCache(
           $pool,
           namespace: 'application',
           generation: '2026-10-01',
           failOpen: true,
       )
       ->factory('settings', SettingsFactory::definition())
       ->cacheDefinition('settings');

Only explicitly eligible singleton factory definitions participate.

Warmup
------

.. code-block:: php

   $report = $builder->warmDefinitionCache();

The report contains hit, written, skipped, and failed counts.

Safety
------

Cache namespace and generation are explicit. Each selected environment has a distinct
key identity, including unset versus a literal ``default`` environment. The
explicit generation is the cross-builder invalidation boundary; local
configuration revisions do not alter it. Corrected keys miss older
non-environment-isolated entries without reading them back. Rotate generation
when changing deployments and retire unused entries through the provider.
InterMix does not clear unrelated pool entries. Cyclic, too-deep, too-broad, object-containing, or otherwise unsupported values remain usable in-process but bypass external persistence.

When CacheLayer 4 and Runwire 3.0 are used together, the host-owned runtime/request/coroutine context is forwarded through the documented integration boundary rather than recreated by InterMix.
