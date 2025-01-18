<?php

use Infocyph\InterMix\Remix\MacroMix;

class MacroTestClass
{
    use MacroMix;

    public const ENABLE_LOCK = true;

    public string $name = '';
}

it('can register and call macros', function () {
    MacroTestClass::macro('sayHello', fn () => 'Hello, MacroMix!');
    expect(MacroTestClass::sayHello())->toBe('Hello, MacroMix!');
});

it('can check if a macro is registered', function () {
    MacroTestClass::macro('sayGoodbye', fn () => 'Goodbye, MacroMix!');
    expect(MacroTestClass::hasMacro('sayGoodbye'))->toBeTrue()
        ->and(MacroTestClass::hasMacro('nonExistent'))->toBeFalse();
});

it('can remove a registered macro', function () {
    MacroTestClass::macro('temporaryMacro', fn () => 'I will be removed');
    MacroTestClass::removeMacro('temporaryMacro');
    expect(MacroTestClass::hasMacro('temporaryMacro'))->toBeFalse();
});

it('supports method chaining in macros', function () {
    MacroTestClass::macro('setName', function ($name) {
        $this->name = $name;

        return $this; // Enable method chaining
    });

    $object = new MacroTestClass;
    $object->setName('MacroMix')->setName('Chained!');
    expect($object->name)->toBe('Chained!');
});

it('can mix methods from another class', function () {
    $mixin = new class
    {
        public function greet($name)
        {
            return "Hello, $name!";
        }

        protected function whisper($message)
        {
            return "psst... $message";
        }
    };

    MacroTestClass::mix($mixin);

    $object = new MacroTestClass;

    expect($object->greet('World'))
        ->toBe('Hello, World!')
        ->and($object->whisper('John'))->toBe('psst... John');
});

it('can load macros from a configuration array', function () {
    $config = [
        'uppercase' => fn ($value) => strtoupper($value),
        'reverse' => fn ($value) => strrev($value),
    ];

    MacroTestClass::loadMacrosFromConfig($config);

    $object = new MacroTestClass;
    expect($object->uppercase('hello'))
        ->toBe('HELLO')
        ->and($object->reverse('hello'))->toBe('olleh');
});

it('can load macros from annotations', function () {
    $mixin = new class
    {
        /**
         * @Macro("shout")
         */
        public function shout($value)
        {
            return strtoupper($value).'!';
        }
    };

    MacroTestClass::loadMacrosFromAnnotations($mixin);

    $object = new MacroTestClass;
    expect($object->shout('hello'))->toBe('HELLO!');
});

it('can retrieve all registered macros', function () {
    MacroTestClass::macro('macroOne', fn () => 'Macro 1');
    MacroTestClass::macro('macroTwo', fn () => 'Macro 2');

    $macros = MacroTestClass::getMacros();
    expect(array_keys($macros))
        ->toContain('macroOne', 'macroTwo')
        ->and($macros['macroOne']())->toBe('Macro 1')
        ->and($macros['macroTwo']())->toBe('Macro 2');
});

it('throws an exception when calling an undefined macro', function () {
    $object = new MacroTestClass;
    expect(fn () => $object->undefinedMacro())
        ->toThrow(Exception::class, sprintf('Method %s::undefinedMacro does not exist.', MacroTestClass::class));
});
