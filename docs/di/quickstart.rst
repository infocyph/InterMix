.. _di.quickstart:

================
Quick start
================

.. code-block:: php

   use Infocyph\InterMix\DI\ContainerBuilder;
   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   final class Clock {}

   final class RequestContext
   {
       public function __construct(public string $id) {}
   }

   final class Service
   {
       public function __construct(
           public Clock $clock,
           public RequestContext $request,
       ) {}
   }

   $builder = ContainerBuilder::create('demo')
       ->autowire(Clock::class, Clock::class)
       ->input(RequestContext::class)
       ->autowire(
           Service::class,
           Service::class,
           lifetime: LifetimeEnum::Scoped,
       );

   $runtime = $builder->build();

   $service = $runtime->withinScope(
       'request-1',
       static fn ($active) => $active->get(Service::class),
       [RequestContext::class => new RequestContext('request-1')],
   );

Providers are imported before finalization:

.. code-block:: php

   final class AppProvider implements ServiceProviderInterface
   {
       public function register(ContainerBuilder $builder): void
       {
           $builder->value('app.name', 'demo');
       }
   }

   $builder->import(new AppProvider());

Use compile() plus production() when deploying a generated runtime.
