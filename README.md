# InterMix

InterMix is a PHP 8.4+ dependency-injection and runtime utility library focused on explicit configuration, immutable runtime wiring, scoped execution, compiled resolution, and low host overhead.

InterMix 11 separates configuration from execution:

- ContainerBuilder owns definitions and configuration.
- Container and ProductionContainer execute a finalized graph.
- RuntimeContainerInterface is the common runtime contract.
- Scoped work uses structured scope callbacks instead of manual enter/leave pairs.
- Optional Runwire and CacheLayer integration remains host-owned.

## Installation

~~~bash
composer require infocyph/intermix
~~~

Optional integrations:

~~~bash
composer require infocyph/cachelayer:^4.0
composer require infocyph/runwire:^2.1
composer require opis/closure:^4.5
~~~

## Quick start

~~~php
<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class Clock {}

final class RequestContext
{
    public function __construct(public string $requestId) {}
}

final class Handler
{
    public function __construct(
        public Clock $clock,
        public RequestContext $request,
    ) {}
}

$builder = ContainerBuilder::create('app')
    ->autowire(Clock::class, Clock::class)
    ->input(RequestContext::class)
    ->autowire(
        Handler::class,
        Handler::class,
        lifetime: LifetimeEnum::Scoped,
        tags: ['request-handler'],
    );

$runtime = $builder->build();

$result = $runtime->withinScope(
    'request-123',
    static fn ($active) => $active->get(Handler::class),
    [RequestContext::class => new RequestContext('request-123')],
);
~~~

## Explicit definition kinds

Use one builder operation per intent:

~~~php
$builder
    ->value('app.name', 'InterMix')
    ->autowire(Logger::class, JsonLogger::class)
    ->factory('token', static fn () => bin2hex(random_bytes(16)))
    ->alias(Psr\Log\LoggerInterface::class, Logger::class)
    ->input(RequestContext::class);
~~~

A value is always returned literally. A factory is always executed as a factory. Class construction is declared with autowire.

For compilation-safe construction recipes use FactoryDefinition:

~~~php
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

$builder->factory(
    Mailer::class,
    FactoryDefinition::construct(Mailer::class, [
        new ServiceReference(MailerConfig::class),
    ]),
);
~~~

## Runtime operations

The runtime contract is intentionally small:

~~~php
$service = $runtime->get(Service::class);
$fresh = $runtime->make(Job::class, ['name' => 'daily']);
$result = $runtime->invoke([$service, 'handle'], ['payload' => $payload]);

foreach ($runtime->tagged('listener') as $id => $listener) {
    $listener->handle($event);
}
~~~

## Scopes

Declare request/job supplied values before finalization, then seed them when a scope begins:

~~~php
$builder
    ->input(RequestContext::class)
    ->autowire(
        RequestService::class,
        RequestService::class,
        lifetime: LifetimeEnum::Scoped,
    );

$runtime = $builder->build();

$response = $runtime->withinScope(
    'request-42',
    static fn ($active) => $active->get(RequestService::class),
    [RequestContext::class => $context],
);
~~~

For child work, capture the active ScopeContext and reattach it with withinScopeContext. The borrower attaches and detaches only; the owner closes the scope.

## Compiled production runtime

~~~php
$builder = ContainerBuilder::create('app')
    ->releaseIdentity('2026-10-01')
    ->autowire(Logger::class, JsonLogger::class)
    ->autowire(Mailer::class, Mailer::class);

$report = $builder->compile(__DIR__ . '/var/intermix.php', strict: true);
$runtime = $builder->production(__DIR__ . '/var/intermix.php');
~~~

The generated artifact is validated against the finalized graph, InterMix ABI, PHP runtime, environment identity, release identity, and fallback requirements before activation.

## Definition cache

Definition caching is explicit, PSR-6 based, and opt-in per eligible factory definition:

~~~php
$builder
    ->definitionCache($pool, namespace: 'app', generation: '2026-10-01')
    ->factory('settings', SettingsFactory::definition())
    ->cacheDefinition('settings');

$report = $builder->warmDefinitionCache();
~~~

Cache failures can remain fail-open when configured, but unsupported or over-budget values are never forced into external persistence.

## Runwire integration

InterMix does not create or own a Runwire runtime. The host supplies its existing runtime/request/coroutine context through Infocyph\InterMix\Integration\Runwire\RunwireIntegration. See docs/integrations.rst.

## Upgrade from 10.1

InterMix 11 intentionally removes mutable runtime configuration, manager proxies, process-global container lookup, descriptor-style invocation, and manual scope lifecycle APIs. See docs/upgrade-11.0.rst for the migration table.

## Quality and performance

The release gates use PHPForge QA/analysis, PHP 8.4 and 8.5, stable/lowest dependency lanes, clean production install, Swoole/OpenSwoole scope-carrier checks, component regression measurements, and representative persistent-host acceptance.

License: MIT.
