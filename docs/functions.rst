.. _functions:

==========================
Global Functions Reference
==========================

InterMix autoloads helper functions from ``src/functions.php``.
This page documents each public helper and its runtime behavior.

DI Helpers
----------

container()
===========

.. php:function:: container(string|Closure|callable|array|null $closureOrClass = null, string $alias = __DIR__): mixed

Get the helper container (default alias ``__DIR__``), or resolve/call immediately.

- ``container()`` returns ``Container::instance(__DIR__)``.
- ``container('my-alias')`` resolves ``'my-alias'`` as a service/callable.
- For explicit named containers, use ``Container::instance('my-alias')``.
- For stable runtime behavior, prefer explicit aliases across bootstrap and app code.

resolve()
=========

.. php:function:: resolve(string|Closure|callable|array|null $spec = null, array $parameters = [], string $alias = __DIR__ . 'DI'): mixed

Resolve immediately with DI/autowiring path enabled.

- ``resolve()`` returns container instance for the helper alias.
- ``resolve($spec, $parameters)`` delegates to ``resolveNow(...)``.

direct()
========

.. php:function:: direct(string|Closure|callable|array|null $spec = null, array $parameters = [], string $alias = __DIR__ . 'DR'): mixed

Resolve immediately with injection disabled (generic invocation path).

- Useful when you want strict/manual argument flow without attribute-based injection.

Functional Helpers
------------------

tap(), when(), pipe(), measure(), retry()
=================================================

These helpers are documented in detail in:

- :ref:`remix.tap-proxy`
- :ref:`remix.helpers`
