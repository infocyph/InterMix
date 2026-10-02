.. _release-11.0:

================================================
InterMix 11.0 release notes
================================================

InterMix 11.0 moves dependency-injection configuration to ContainerBuilder and freezes runtime wiring after successful finalization.

Highlights
----------

* Explicit value(), autowire(), factory(), alias(), and input() definitions.
* RuntimeContainerInterface for PSR-11 access, fresh construction, invocation, tags, and structured scopes.
* Strict scoped-input and captive-dependency rules for persistent hosts.
* Frozen dynamic/compiled parity with versioned artifact metadata and atomic build activation.
* Explicit PSR-6 definition caching with bounded admission.
* Optional host-owned Runwire 2.1 and CacheLayer 4 context sharing.
* Carrier-local construction ancestry and bounded/weak carrier process state.

Breaking migration
------------------

Mutable runtime configuration, manager proxies, process-global container lookup, legacy descriptor invocation, getReturn(), manual public scope enter/leave, and mutable compiled-runtime deoptimization are removed from the public contract.

See the InterMix 11.0 upgrade guide for direct replacements.

Deployment and rollback
-----------------------

Compile versioned artifacts outside request traffic and switch the complete release/runtime at a host lifecycle boundary. Generated PHP and metadata are trusted deployment assets.

Rollback the complete prior release, generated artifacts, dependency set, and compatible cache namespace/configuration together. Do not mix 11.0 generated artifacts with 10.x runtimes or assume CacheLayer 3 and 4 storage are interchangeable.
