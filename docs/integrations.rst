================================================
Runwire and Cache Integration
================================================

InterMix 11 keeps runtime and cache providers optional. Normal PHP execution does
not require Runwire or CacheLayer.

Supported targets
-----------------

The InterMix 11 integration matrix targets ``infocyph/runwire:^2.1`` and
``infocyph/cachelayer:^4.0``. Core definition caching remains a PSR-6 boundary;
production InterMix code does not require CacheLayer.

Runwire ownership
-----------------

The host owns the Runwire runtime, request, coroutine scope, worker/event-loop,
listener, signal and cancellation lifecycles. InterMix never creates or closes
those host-owned objects.

Declare the Runwire execution contexts as scoped inputs during bootstrap:

.. code-block:: php

    use Infocyph\InterMix\DI\ContainerBuilder;
    use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;

    $builder = ContainerBuilder::create('application');
    RunwireIntegration::registerInputs($builder);

    $container = $builder->build();
    $integration = new RunwireIntegration(
        $container,
        ownsCacheLayerLifecycle: true,
    );

At worker bootstrap bind the host's existing ``RuntimeContext``:

.. code-block:: php

    $integration->bind($runtimeContext);

At request/task entry pass the host's existing ``RequestContext`` and optional
``CoroutineScope``:

.. code-block:: php

    $result = $integration->withinRequest(
        $requestContext,
        $coroutineScope,
        static fn($container) => $container->get(App\Handler::class),
    );

``withinRequest()`` owns only the InterMix logical DI scope. The exact Runwire
runtime/request/scope instances are seeded into their declared scoped inputs and
the DI frame is closed in ``finally``.

Child tasks
-----------

When the host spawns Runwire child work, wrap the child callback at the spawn
boundary:

.. code-block:: php

    $task = $scope->spawn(
        $integration->wrapChild(
            $requestContext,
            $scope,
            static fn($container) => $container->get(App\Job::class)->run(),
        ),
    );

The wrapper uses Runwire's inherited ``TaskLocal`` snapshot to attach the same
process-local InterMix ``ScopeContext``. A borrowed DI scope handle is attached
and detached only; InterMix never closes the owner's Runwire scope.

The host must await or join child work before completing the request boundary.

CacheLayer forwarding
---------------------

When CacheLayer 4 is installed, InterMix forwards the same ``RuntimeContext``,
``RequestContext`` and ``CoroutineScope`` through CacheLayer's existing Runwire
integration. Only one host-designated bridge should use
``ownsCacheLayerLifecycle: true``. Borrower bridges never release a downstream
worker binding. A conflicting live RuntimeContext is rejected.

Definition caching
------------------

Definition caching is explicit and PSR-6 based. Provide a stable application
namespace, a release/generation identity, and opt in each persistable definition:

.. code-block:: php

    $runtime = ContainerBuilder::create('application')
        ->definitionCache(
            $psr6Pool,
            namespace: 'orders-api',
            generation: '2026.10.01',
        )
        ->factory('settings', static fn() => ['region' => 'ap-south'])
        ->cacheDefinition('settings')
        ->build();

Only scalar, ``null`` and recursively safe array values are persisted. Objects,
closures, resources, cyclic arrays, over-depth values and over-budget values are
not admitted. Cache keys are opaque and include the explicit namespace and
generation.

Cache-provider lifecycle, invalidation, topology and transport configuration stay
with the supplied PSR-6 provider. InterMix does not create or infer a cache
backend.

Failure policy
--------------

Definition-cache failures are fail-open by default. Pass ``failOpen: false`` to
``definitionCache()`` when cache failures must surface.

Runwire context mismatches fail closed. Completed requests, requests bound to a
different runtime, conflicting CacheLayer worker bindings, and coroutine scopes
supplied without ``RUNWIRE_COROUTINES`` capability are rejected.
