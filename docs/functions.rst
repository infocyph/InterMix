.. _functions:

==========================
Global Functions Reference
==========================

Global helpers are optional.
InterMix does **not** autoload ``src/functions.php`` by default.
Load helpers manually when you want them:

.. code-block:: php

   require_once __DIR__ . '/vendor/infocyph/intermix/src/functions.php';

This page documents each helper and its runtime behavior.

DI Helpers
----------

container()
===========

.. php:function:: container(string|Closure|callable|array|null $closureOrClass = null, string $alias = Container::DEFAULT_ALIAS): mixed

Get the helper container (default alias ``intermix.default``), or resolve/call immediately.

- ``container()`` returns ``Container::instance('intermix.default')``.
- ``container('my-alias')`` resolves ``'my-alias'`` as a service/callable.
- For explicit named containers, use ``Container::instance('my-alias')``.
- For stable runtime behavior, prefer explicit aliases across bootstrap and app code.

resolve()
=========

.. php:function:: resolve(string|Closure|callable|array|null $spec = null, array $parameters = [], string $alias = Container::DI_ALIAS): mixed

Resolve immediately with DI/autowiring path enabled.

- ``resolve()`` returns container instance for the helper alias.
- ``resolve($spec, $parameters)`` delegates to ``resolveNow(...)``.

direct()
========

.. php:function:: direct(string|Closure|callable|array|null $spec = null, array $parameters = [], string $alias = Container::DIRECT_ALIAS): mixed

Resolve immediately with injection disabled (generic invocation path).

- Useful when you want strict/manual argument flow without attribute-based injection.

Functional Helpers
------------------

tap(), when(), pipe(), measure(), retry()
=================================================

These helpers are documented in detail in:

- :ref:`remix.tap-proxy`
- :ref:`remix.helpers`
