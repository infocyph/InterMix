.. _di.scopes:

================
Structured scopes
================

Scopes are owned with callbacks so cleanup cannot be skipped accidentally.

.. code-block:: php

   $result = $runtime->withinScope(
       'request-42',
       static fn ($active) => $active->get(RequestService::class),
       [RequestContext::class => $requestContext],
   );

Scope seeds must identify declared scoped definitions or input() entries.

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

Cleanup
-------

All registered scope-leave hooks run. When both application work and cleanup fail, ScopeCleanupException preserves the application failure as previous and retains bounded cleanup failures.

Persistent hosts may call resetCurrentExecutionScope() at an explicit recovery boundary.
