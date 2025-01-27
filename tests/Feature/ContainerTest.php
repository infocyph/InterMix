<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Tests\Fixture\BasicClass;
use Infocyph\InterMix\Tests\Fixture\ClassA;
use Infocyph\InterMix\Tests\Fixture\ClassB;
use Infocyph\InterMix\Tests\Fixture\ClassC;
use Infocyph\InterMix\Tests\Fixture\ClassInit;
use Infocyph\InterMix\Tests\Fixture\ClassInitWInterface;
use Infocyph\InterMix\Tests\Fixture\ClosureExample;
use Infocyph\InterMix\Tests\Fixture\EmailService;
use Infocyph\InterMix\Tests\Fixture\FileLogger;
use Infocyph\InterMix\Tests\Fixture\InterfaceA;
use Infocyph\InterMix\Tests\Fixture\InterfaceB;
use Infocyph\InterMix\Tests\Fixture\InterfaceC;
use Infocyph\InterMix\Tests\Fixture\LoggerInterface;
use Infocyph\InterMix\Tests\Fixture\MultiConstructorArgsClass;
use Infocyph\InterMix\Tests\Fixture\NotificationService;
use Infocyph\InterMix\Tests\Fixture\PropertyClass;
use Infocyph\InterMix\Tests\Fixture\ServiceA;
use Infocyph\InterMix\Tests\Fixture\ServiceB;
use Infocyph\InterMix\Tests\Fixture\TestLoggerStorage;
use Infocyph\InterMix\Tests\Fixture\UserService;

use function Infocyph\InterMix\container;

// -------------------------------------------------------------------------
//   HELPER: For clarity, we define a "fresh container()" in each test or
//   use the "container()" function you provided, which can do per-alias usage.
// -------------------------------------------------------------------------

beforeEach(function () {
    // Optionally clear any static logs or other states before each test
    TestLoggerStorage::reset();
});

// -------------------------------------------------------------------------
//   1) PSR-11 Basic Tests
// -------------------------------------------------------------------------

test('PSR-11: container can get() a class with no constructor', function () {
    $container = Container::instance('basic_test')
        ->lock(); // locking is optional here

    expect($container->has(BasicClass::class))->toBeFalse();

    // Not defined? The container might attempt auto-resolve if your container supports reflection-based
    // or might throw if no auto-resolve is available. If you do reflection auto-resolve, this might pass:
    $instance = $container->get(BasicClass::class);
    expect($instance)
        ->toBeInstanceOf(BasicClass::class)
        ->and($container->has(BasicClass::class))->toBeTrue();
    // now resolved
});

test('PSR-11: container throws on missing definitions if reflection is off', function () {
    $container = Container::instance('missing_test')->lock();

    $container->options()->setOptions(injection: false)->end()->get(BasicClass::class);
})->throws(ContainerException::class); // or NotFoundException, etc.

// -------------------------------------------------------------------------
//   2) Constructor Injection
// -------------------------------------------------------------------------

test('Constructor Injection: interface & scalar param', function () {
    $container = container(null, 'user_service')
        ->options()
        ->setOptions(true, true)
        ->definitions()
        ->addDefinitions([
            LoggerInterface::class => FileLogger::class, // interface => class
        ])
        ->registration()
        ->registerClass(UserService::class, [
            // param 1 => LoggerInterface => FileLogger (above),
            // param 2 => $dbName => 'test_database'
            'test_database',
        ])->end();

    /** @var UserService $service */
    $service = $container->get(UserService::class);
    expect($service)->toBeInstanceOf(UserService::class);

    $service->createUser(['name' => 'Alice']);
    expect(TestLoggerStorage::$logs[0])->toContain('Creating user in test_database');
});

test('Constructor injection: multiple interfaces + scalar', function () {
    $container = container(null, 'multi_interfaces')
        ->options()
        ->setOptions(true, true)
        ->definitions()
        ->addDefinitions([
            InterfaceA::class => ClassA::class,
            InterfaceB::class => ClassB::class,
            InterfaceC::class => ClassC::class,
        ])
        ->registration()
        ->registerClass(ClassInitWInterface::class, [
            // param1 => InterfaceA => ClassA
            // param2 => InterfaceB => ClassB
            // param3 => InterfaceC => ClassC
            'myString' => 'abc', // param4 => string
            'dbS' => 'def',      // param5 => string
        ])->end();

    /** @var ClassInitWInterface $obj */
    $obj = $container->get(ClassInitWInterface::class);
    $vals = $obj->getValues();

    expect($vals['classA'])->toBeInstanceOf(ClassA::class)
        ->and($vals['classB'])->toBeInstanceOf(ClassB::class)
        ->and($vals['classC'])->toBeInstanceOf(ClassC::class)
        ->and($vals['myString'])->toBe('abc')
        ->and($vals['dbS'])->toBe('def');
});

test('Constructor injection: singletons vs. make for ClassInit', function () {
    /** @var \Infocyph\InterMix\DI\Container $container */
    $container = container(null, 'class_init_test')
        ->options()
        ->setOptions(true, true)
        ->registration()
        ->registerClass(ClassInit::class, [
            // param1 => ClassA => auto-resolve or define
            'abc', // param2 => string
            'dbS' => 'def', // param3 => string
        ])->end();

    // 1) The first .get() call is stored as a singleton by default
    $inst1 = $container->get(ClassInit::class);
    $rand1 = $inst1->getValues()['random'];

    // 2) Another .get() => same instance => same random
    $inst2 = $container->get(ClassInit::class);
    $rand2 = $inst2->getValues()['random'];
    expect($rand1)->toEqual($rand2);

    // 3) .make() => fresh new instance => different random
    $newInstance = $container->make(ClassInit::class, 'getValues');
    $rand3 = $newInstance['random'];
    expect($rand3)->not->toEqual($rand1);

    // Another .make() => another fresh
    $newInstance2 = $container->make(ClassInit::class, 'getValues');
    $rand4 = $newInstance2['random'];
    expect($rand4)->not->toEqual($rand3);
});

// -------------------------------------------------------------------------
//   3) Method Injection
// -------------------------------------------------------------------------

test('Method Injection: EmailService setConfig()', function () {
    $container = container(null, 'method_injection')
        ->options()
        ->setOptions(true, true)
        ->registration()
        ->registerClass(EmailService::class)
        ->registerMethod(EmailService::class, 'setConfig', [
            // supply an array
            ['smtp' => 'localhost', 'port' => 25],
        ])->end();

    /** @var EmailService $service */
    $service = $container->get(EmailService::class);
    // No exception => setConfig was called => $initialized = true
    expect($service->send('john@example.com', 'Hello', 'Testing method injection'))->toBeTrue();
});

// Also see "ClassA->resolveIt()" in your sample method attribute tests below

// -------------------------------------------------------------------------
//   4) Property Injection
// -------------------------------------------------------------------------

test('Property Injection: ParentPropertyClass & PropertyClass', function () {
    $container = container(null, 'property_injection')
        ->options()
        ->setOptions(true, false, true)
        ->registration()
        ->registerProperty(PropertyClass::class, [
            'nothing' => 'assigned_value',
            'staticValue' => 'someStatic',
        ])
        ->definitions()
        ->addDefinitions([
            'db.host' => '127.0.0.1',
            'db.port' => '54321',
        ])->end();

    /** @var PropertyClass $obj */
    $obj = $container->get(PropertyClass::class);

    // "nothing" assigned via registerProperty
    expect($obj->nothing)
        ->toBe('assigned_value')
        ->and($obj->something)->toBe('127.0.0.1')
        ->and($obj->getDbPort())->toBe('54321')
        ->and($obj->yesterday)->toBe(strtotime('last monday'))
        ->and($obj->yesterdayFromADate)->toBe(strtotime('last monday', 1678786990))
        ->and($obj->getStaticValue())->toBe('someStatic');
    // "db.host" assigned to $something => '127.0.0.1'
    // parent's "db.port" => '54321'
    // "yesterday" => strtotime('last monday')

    // "yesterdayFromADate" => strtotime('last monday', 1678786990)

    // staticValue => 'someStatic'
});

test('Property Injection: NotificationService -> logger', function () {
    $container = container(null, 'notification')
        ->options()
        ->setOptions(true, false, true)
        ->definitions()
        ->addDefinitions([
            LoggerInterface::class => FileLogger::class,
        ])->end();

    /** @var NotificationService $service */
    $service = $container->get(NotificationService::class);
    $service->notify('Test message');

    expect(TestLoggerStorage::$logs[0])->toContain('Notification: Test message');
});

// -------------------------------------------------------------------------
//   5) Attribute-based Method Injection (ClassA->resolveIt())
// -------------------------------------------------------------------------

test('Method injection with attribute Infuse (ClassA->resolveIt)', function () {
    $container = container(null, 'classA_method_test')
        ->options()
        ->setOptions(true, true)
        ->definitions()
        ->addDefinitions([
            'db.host' => '127.0.0.1',
        ])
        ->registration()
        ->registerMethod(ClassA::class, 'resolveIt', [
            'abc',     // param => parameterA (non-associative)
            'def',     // leftover => goes into variadic? Actually param => ??? we can reorder
            'parameterB' => 'ghi',  // associative => => param named $parameterB
            'jkl',     // leftover => goes into variadic
        ])->end();

    /** @var array $result */
    $result = $container->getReturn(ClassA::class);
    // "getReturn()" calls the method and returns its result

    // 'classB' => instance of ClassB
    expect($result['classB'])
        ->toBeInstanceOf(ClassB::class)
        ->and($result['parameterA'])->toBe(gethostname())
        ->and($result['parameterB'])->toBe('ghi')
        ->and($result['parameterC'])->toBe(['abc', 'def', 'jkl']);
    // 'parameterA' => 'abc' (non-associative)
    // 'parameterB' => 'ghi' from the associative param
    // leftover => 'def', 'jkl'
});

test('Method injection with attribute supply from definition (ClassA->resolveIt)', function () {
    $container = container(null, 'classA_attribute')
        ->options()
        ->setOptions(true, true)
        ->definitions()
        ->addDefinitions([
            'db.host' => '127.0.0.1',
        ])
        ->registration()
        ->registerMethod(ClassA::class, 'resolveIt')->end();
    // no explicit param => fallback to attribute:
    // parameterA => gethostname()
    // parameterB => 'db.host' => '127.0.0.1'

    $result = $container->getReturn(ClassA::class);

    expect($result['classB'])
        ->toBeInstanceOf(ClassB::class)
        ->and($result['parameterA'])->toBe(gethostname())
        ->and($result['parameterB'])->toBe('127.0.0.1')
        ->and($result['parameterC'])->toBeArray()->toBeEmpty();
    // parameterA => gethostname()
    // parameterB => '127.0.0.1'
    // leftover => []
});

// -------------------------------------------------------------------------
//   6) Closure Definitions
// -------------------------------------------------------------------------

test('Closure definitions for a custom service', function () {
    $container = container(null, 'closure_test')
        ->definitions()
        ->bind('example.closure', function () {
            return new ClosureExample();
        })
        ->end();

    $closureObj = $container->get('example.closure'); // returns new ClosureExample
    expect($closureObj)->toBeInstanceOf(ClosureExample::class);

    // If we treat the object as an invokable:
    $result = $closureObj('Hello');
    expect($result)->toBe('ClosureExample says: Hello');
});

// -------------------------------------------------------------------------
//   7) Circular Dependency
// -------------------------------------------------------------------------

test('Circular dependency: ServiceA <-> ServiceB should fail', function () {
    $container = container(null, 'circular_test')
        ->options()
        ->setOptions(true, true)->end();

    // If reflection-based resolution is on, it tries to instantiate
    // ServiceA => needs ServiceB => needs ServiceA => cycle => should throw
    $container->get(ServiceA::class);
})->throws(ContainerException::class);

// -------------------------------------------------------------------------
//   8) Additional Cases
//   - multi constructor args (MultiConstructorArgsClass)
// -------------------------------------------------------------------------

test('Multiple constructor arguments with optional interface in MultiConstructorArgsClass', function () {
    $container = container(null, 'multi_args')
        ->options()
        ->setOptions(true, true)
        ->definitions()
        ->addDefinitions([
            LoggerInterface::class => FileLogger::class,
        ])
        ->registration()
        ->registerClass(MultiConstructorArgsClass::class, [
            'JohnDoe', // param1 => string $name
            42,        // param2 => int $count
            // param3 => ?LoggerInterface => bound to FileLogger
        ])->end();

    /** @var MultiConstructorArgsClass $obj */
    $obj = $container->get(MultiConstructorArgsClass::class);
    $info = $obj->info();

    expect($info)->toContain('Name=JohnDoe', 'Count=42', 'Logger='.FileLogger::class);
});

// You can keep adding more advanced tests if needed
