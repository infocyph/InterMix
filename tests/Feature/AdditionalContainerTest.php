<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Tests\Fixture\BasicClass;
use Infocyph\InterMix\Tests\Fixture\ClassA;
use Infocyph\InterMix\Tests\Fixture\ClassB;
use Infocyph\InterMix\Tests\Fixture\ClassC;
use Infocyph\InterMix\Tests\Fixture\FileLogger;
use Infocyph\InterMix\Tests\Fixture\InterfaceA;
use Infocyph\InterMix\Tests\Fixture\ClassInit;
use Infocyph\InterMix\Tests\Fixture\PropertyClass;
use Infocyph\InterMix\Tests\Fixture\EmailService;
use Infocyph\InterMix\Tests\Fixture\TestLoggerStorage;

use function Infocyph\InterMix\container;

beforeEach(function () {
    // Reset any static logs or other global states
    TestLoggerStorage::reset();
});

/*
|--------------------------------------------------------------------------
| 1) Environment-Based Interface Override
|--------------------------------------------------------------------------
*/
test('Environment-based interface override (separate containers for each environment)', function () {
    // 1) "production" container
    $prodContainer = Container::instance('env_override_production')
        ->options()
        ->setOptions(true, true)
        ->setEnvironment('production')
        ->bindInterfaceForEnv('production', InterfaceA::class, ClassA::class)
        ->bindInterfaceForEnv('local', InterfaceA::class, ClassC::class)
        ->end();

    // We expect InterfaceA => ClassA in "production"
    $resolvedProd = $prodContainer->get(InterfaceA::class);
    expect($resolvedProd)->toBeInstanceOf(ClassA::class);

    // 2) "local" container
    // Build a *new* container instance with a different alias,
    // also applying environment-based bindings:
    $localContainer = Container::instance('env_override_local')
        ->options()
        ->setOptions(true, true)
        ->setEnvironment('local')
        ->bindInterfaceForEnv('production', InterfaceA::class, ClassA::class)
        ->bindInterfaceForEnv('local', InterfaceA::class, ClassC::class)
        ->end();

    // We expect InterfaceA => ClassC in "local"
    $resolvedLocal = $localContainer->get(InterfaceA::class);
    expect($resolvedLocal)->toBeInstanceOf(ClassC::class);
});


/*
|--------------------------------------------------------------------------
| 2) Lock container forbids modifications
|--------------------------------------------------------------------------
*/
test('Lock container forbids modifications', function () {
    $container = Container::instance('lock_test');
    $container->lock();

    // Attempt to modify after locking => should throw
    $container->registration()->registerClass(BasicClass::class, []);
})->throws(ContainerException::class);

/*
|--------------------------------------------------------------------------
| 3) Switching between injected and generic calls
|--------------------------------------------------------------------------
*/
test('Switching between injected and generic calls', function () {
    $container = Container::instance('switch_resolver_test')
        ->options()
        ->setOptions(injection: true)
        ->end();

    // Should be InjectedCall by default
    $resolver1 = $container->getCurrentResolver();
    expect($resolver1)->toBeInstanceOf(InjectedCall::class);

    // Switch to generic
    $container->options()->setOptions(injection: false)->end();
    $resolver2 = $container->getCurrentResolver();
    expect($resolver2)->toBeInstanceOf(GenericCall::class);

    // Switch back to injected
    $container->options()->setOptions(injection: true)->end();
    $resolver3 = $container->getCurrentResolver();
    expect($resolver3)->toBeInstanceOf(InjectedCall::class);
});

/*
|--------------------------------------------------------------------------
| 4) Auto-resolve an unregistered class
|--------------------------------------------------------------------------
*/
test('Auto-resolve an unregistered class when injection is on', function () {
    $container = container(null, 'auto_resolve_on')
        ->options()
        ->setOptions(injection: true)
        ->end();

    // BasicClass is not explicitly registered
    $instance = $container->get(BasicClass::class);
    expect($instance)->toBeInstanceOf(BasicClass::class);
});

test('Auto-resolve an unregistered class when injection is off', function () {
    $container = container(null, 'auto_resolve_off')
        ->options()
        ->setOptions(injection: false)
        ->end();

    expect($container->get(BasicClass::class))->toBeInstanceOf(BasicClass::class);
});

/*
|--------------------------------------------------------------------------
| 5) Property injection with static property
|--------------------------------------------------------------------------
*/
test('Property injection sets a static property', function () {
    $container = container(null, 'static_prop')
        ->options()
        ->setOptions(true, false, true)
        ->registration()
        ->registerProperty(PropertyClass::class, [
            'staticValue' => 'STATIC_PROOF'
        ])
        ->definitions()
        ->addDefinitions([
            'db.host' => '127.0.0.1',
            'db.port' => '54321'
        ])
        ->end();

    /** @var PropertyClass $instance */
    $instance = $container->get(PropertyClass::class);
    expect($instance->getStaticValue())->toBe('STATIC_PROOF');
});

/*
|--------------------------------------------------------------------------
| 6) Overwriting a previously bound definition
|--------------------------------------------------------------------------
*/
test('Overwriting a previously bound definition', function () {
    $container = container(null, 'overwrite_test')
        ->definitions()
        ->bind('logger', FileLogger::class)
        ->bind('logger', ClassA::class) // Overwrite
        ->end();

    $logger = $container->get('logger');
    // The second bind should have overwritten the first
    expect($logger)->toBeInstanceOf(ClassA::class);
});

/*
|--------------------------------------------------------------------------
| 8) Method injection with leftover parameters not matching method signature
|--------------------------------------------------------------------------
*/
test('Method injection with leftover parameters that are not variadic', function () {
    $container = container(null, 'leftover_params')
        ->options()
        ->setOptions(true, true)
        ->registration()
        ->registerClass(EmailService::class)
        ->registerMethod(EmailService::class, 'setConfig', [
            ['smtp' => 'localhost', 'port' => 25],
            'extraParam1',
            'extraParam2'
        ])
        ->end();

    /** @var EmailService $service */
    $service = $container->get(EmailService::class);

    // If leftover params are ignored, this passes
    expect($service->send('john@example.com', 'Hello', 'Testing leftover params'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 9) Infuse attribute referencing an unknown definition
|--------------------------------------------------------------------------
*/
class InfuseUnknownParam
{
    #[\Infocyph\InterMix\DI\Attribute\Infuse('unknown.ref')]
    public function doSomething(BasicClass $basic, string $another): array
    {
        return [
            'basic' => $basic,
            'another' => $another
        ];
    }
}

test('Infuse attribute with unknown reference', function () {
    $container = container(null, 'infuse_unknown')
        ->options()
        ->setOptions(true, true)
        ->end();
    $container->call(InfuseUnknownParam::class, 'doSomething');
})->throws(ContainerException::class);
