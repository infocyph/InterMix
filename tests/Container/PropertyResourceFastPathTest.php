<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

final class PropertyFastPathSubject
{
    public string $value = 'initial';

    public function touch(): void {}
}

it('keeps unrelated class resources off the property-resolution fast path', function () {
    $container = new ConfigurationContainer(uniqid('property_fast_flag_'));
    $repository = $container->getRepository();

    expect($repository->hasPropertyResources())->toBeFalse();

    $container->registration()->registerClass(PropertyFastPathSubject::class);
    expect($repository->hasPropertyResources())->toBeFalse();

    $container->registration()->registerMethod(PropertyFastPathSubject::class, 'touch');
    expect($repository->hasPropertyResources())->toBeFalse();
});

it('activates the property-resource flag on late property registration', function (bool $attributes) {
    $container = new ConfigurationContainer(uniqid('property_fast_late_'));
    $container->options()->setOptions(propertyAttributes: $attributes);
    $container->bind(
        PropertyFastPathSubject::class,
        PropertyFastPathSubject::class,
        LifetimeEnum::Transient,
    );
    $repository = $container->getRepository();

    $before = $container->get(PropertyFastPathSubject::class);
    expect($repository->hasPropertyResources())->toBeFalse()
        ->and($before->value)->toBe('initial');

    $container->registration()->registerProperty(
        PropertyFastPathSubject::class,
        ['value' => 'registered'],
    );

    $after = $container->get(PropertyFastPathSubject::class);
    expect($repository->hasPropertyResources())->toBeTrue()
        ->and($after->value)->toBe('registered');
})->with([false, true]);

it('preserves verbose property tracing after caching an unattributed class', function () {
    $container = new ConfigurationContainer(uniqid('property_fast_trace_'));
    $container->options()->setOptions(propertyAttributes: true);
    $container->bind(PropertyFastPathSubject::class, PropertyFastPathSubject::class, LifetimeEnum::Transient);
    $container->get(PropertyFastPathSubject::class);
    $container->options()->enableDebugTracing(true, TraceLevelEnum::Verbose);
    $container->get(PropertyFastPathSubject::class);

    expect(array_column($container->tracer()->getEntries(), 'message'))
        ->toContain('prop value of ' . PropertyFastPathSubject::class);
});
