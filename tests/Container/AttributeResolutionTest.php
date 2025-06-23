<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\ExampleAttr;
use Infocyph\InterMix\Tests\Fixture\ExampleAttrResolver;
use Infocyph\InterMix\Tests\Fixture\LogicOnlyAttr;
use Infocyph\InterMix\Tests\Fixture\LogicOnlyAttrResolver;
use Infocyph\InterMix\Tests\Fixture\LogicOnlyTarget;
use Infocyph\InterMix\Tests\Fixture\MethodTarget;
use Infocyph\InterMix\Tests\Fixture\MixedAttributeExample;

uses()->group('di', 'attribute');

beforeEach(function () {
    $this->container = new Container();
});

it('resolves built-in and custom attributes', function () {
    $c = $this->container;

    $c->options()->setOptions(
        injection: true,
        methodAttributes: true,
        propertyAttributes: true,
    );

    $c->attributeRegistry()->register(
        ExampleAttr::class,
        ExampleAttrResolver::class,
    );

    $c->definitions()->bind('name', 'hello');
    $instance = $c->get(MixedAttributeExample::class);

    // Skip $std as it resolves to IMStdClass by design
    expect($instance->name)
        ->toBe('hello')
        ->and($instance->custom)->toBe('TEST');
});

it('supports method parameter attribute injection', function () {
    $c = $this->container;             // provided by pest’s beforeEach / helper

    /* 1️⃣  Enable attribute parsing on methods & properties */
    $c->options()->setOptions(
        injection:           true,
        methodAttributes:    true,
        propertyAttributes:  true,
    );

    /* 2️⃣  Register custom attribute + resolver */
    $c->attributeRegistry()->register(
        ExampleAttr::class,
        ExampleAttrResolver::class,
    );

    /* 3️⃣  Bind a container definition that Infuse will pick up */
    $c->definitions()->bind('api_key', 'XYZ123');

    /* 4️⃣  Pre-register the method we want the container to invoke */
    $c->registration()->registerMethod(
        MethodTarget::class,
        'send',                          // ← will be called automatically
        ['override' => 'FOO'],           // user-supplied arg (merges with attrs)
    );

    /* 5️⃣  Resolve — constructor + send() are executed */
    $target = $c->get(MethodTarget::class);

    /* 6️⃣  Assert the merged result from:
            - user arg  ('override' => 'FOO')
            - ExampleAttr resolver ('custom'  => 'TEST')                     */
    expect($target->result)->toBe([
        'override' => 'FOO',
        'custom'   => 'TEST',
    ]);
});

it('supports logic-only custom attribute', function () {
    $c = $this->container;

    $c->attributeRegistry()->register(
        LogicOnlyAttr::class,
        LogicOnlyAttrResolver::class,
    );

    $c->options()->setOptions(
        methodAttributes: true,
        propertyAttributes: true,
    );

    // Expect no failure (resolved, no injection)
    $instance = $c->get(LogicOnlyTarget::class);
    expect($instance)->toBeInstanceOf(LogicOnlyTarget::class);
});
