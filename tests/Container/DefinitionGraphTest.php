<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class DefinitionGraphDependency {}

final readonly class DefinitionGraphConsumer
{
    public function __construct(public DefinitionGraphDependency $dependency) {}
}

it('captures an immutable effective build-time definition snapshot', function () {
    $container = Container::instance(uniqid('definition_graph_'));
    $contextual = new DefinitionGraphDependency();

    $container->bind('service', DefinitionGraphDependency::class, tags: ['base']);
    $container->options()->setDefinitionMetaForEnv(
        'production',
        'service',
        LifetimeEnum::Transient,
        ['production'],
    );
    $container->setEnvironment('production');
    $container->when(DefinitionGraphConsumer::class)
        ->needs(DefinitionGraphDependency::class)
        ->give($contextual);

    $graph = DefinitionGraph::from($container->getRepository());

    $container->unbind('service');
    $container->setEnvironment('development');
    $container->when(DefinitionGraphConsumer::class)
        ->needs(DefinitionGraphDependency::class)
        ->give(new DefinitionGraphDependency());

    expect($graph->hasDefinition('service'))->toBeTrue()
        ->and($graph->definitions()['service'])->toBe(DefinitionGraphDependency::class)
        ->and($graph->definitionMetaFor('service')['lifetime'])->toBe(LifetimeEnum::Transient)
        ->and($graph->definitionMetaFor('service')['tags'])->toBe(['production'])
        ->and($graph->environment())->toBe('production')
        ->and($graph->hasContextualBinding(
            DefinitionGraphConsumer::class,
            DefinitionGraphDependency::class,
        ))->toBeTrue()
        ->and($graph->contextualBinding(
            DefinitionGraphConsumer::class,
            DefinitionGraphDependency::class,
        ))->toBe($contextual);
});
