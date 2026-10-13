.. _di.scopes:

================================================
Structured scopes
================================================

Scopes are owned with callbacks so cleanup cannot be skipped accidentally.

.. code-block:: php

   $result = $runtime->withinScope(
       'request-42',
       static fn ($active) => $active->get(RequestService::class),
       [RequestContext::class => $requestContext],
   );

Scope seeds must identify declared scoped definitions or input() entries.
Aliases cannot own seeds; supply a seed under the declared target identifier.
Declared inputs have scoped lifetime in both dynamic and compiled runtimes.
Missing inputs, undeclared seeds and input resolution outside an active scope
fail closed. A supplied null remains a value; the consuming parameter must allow it.
Nested scopes require their own seeds, while borrowed scope contexts share their
owner's values. Reusing a scope name in a later execution does not reuse its values.

Inputs compile natively without requiring a dynamic factory or fallback graph.
See :ref:`di.compiled-resolvers` for source-free artifact loading.

Child work
----------

Capture the active ScopeContext and attach it around borrowed child work:

.. code-block:: php

   $context = $runtime->captureScopeContext();

   $childResult = $runtime->withinScopeContext(
       $context,
       static fn ($active) => $active->get(RequestService::class),
   );

Borrowers attach/detach only. They do not close the owner's scope.
The host must join borrowed child work before the owner leaves its scope.

Cleanup
-------

All registered scope-leave hooks run. When both application work and cleanup fail, ScopeCleanupException preserves the application failure as previous and retains bounded cleanup failures.

Persistent hosts may call resetCurrentExecutionScope() at an explicit recovery boundary.
