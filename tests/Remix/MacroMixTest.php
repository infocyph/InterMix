<?php

use Infocyph\InterMix\Remix\MacroMix;

class MacroTestClass
{
    use MacroMix;
    public const ENABLE_LOCK = true;
    public string $name = '';
}

it('registers & calls macros', function () {
    MacroTestClass::macro('sayHello', fn () => 'Hello, MacroMix!');
    expect(MacroTestClass::sayHello())->toBe('Hello, MacroMix!');
});

it('detects & removes macros', function () {
    MacroTestClass::macro('sayGoodbye', fn () => 'Goodbye, MacroMix!');
    expect(MacroTestClass::hasMacro('sayGoodbye'))->toBeTrue();
    MacroTestClass::removeMacro('sayGoodbye');
    expect(MacroTestClass::hasMacro('sayGoodbye'))->toBeFalse();
});

it('supports method chaining in macros', function () {
    // Use a block closure that returns $this, not an arrow that returns the assignment result
    MacroTestClass::macro('setName', function ($name) {
        $this->name = $name;
        return $this; // Ensure method chaining
    });

    $object = new MacroTestClass();
    $object->setName('MacroMix')->setName('Chained!');
    expect($object->name)->toBe('Chained!');
});

it('mixes methods from another class', function () {
    $mixin = new class {
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

    $object = new MacroTestClass();

    expect($object->greet('World'))->toBe('Hello, World!');
    expect($object->whisper('John'))->toBe('psst... John');
});

it('mixes via class string', function () {
    // Confirm mix() accepts class names
    $class = new class {
        public function ping() { return 'pong'; }
    };
    MacroTestClass::mix($class::class);

    expect((new MacroTestClass())->ping())->toBe('pong');
});

it('loads macros from a configuration array', function () {
    $config = [
        'uppercase' => fn ($value) => strtoupper($value),
        'reverse'   => fn ($value) => strrev($value),
    ];

    MacroTestClass::loadMacrosFromConfig($config);

    $object = new MacroTestClass();
    expect($object->uppercase('hello'))->toBe('HELLO');
    expect($object->reverse('hello'))->toBe('olleh');
});

it('loads macros from annotations', function () {
    $mixin = new class {
        /**
         * @Macro("shout")
         */
        public function shout($value)
        {
            return strtoupper($value).'!';
        }
    };

    MacroTestClass::loadMacrosFromAnnotations($mixin);

    expect((new MacroTestClass())->shout('hello'))->toBe('HELLO!');
});

it('retrieves all registered macros', function () {
    MacroTestClass::macro('macroOne', fn () => 'Macro 1');
    MacroTestClass::macro('macroTwo', fn () => 'Macro 2');

    $macros = MacroTestClass::getMacros();
    $keys   = array_keys($macros);

    expect($keys)->toContain('macroOne', 'macroTwo');
    expect($macros['macroOne']())->toBe('Macro 1');
    expect($macros['macroTwo']())->toBe('Macro 2');
});

it('throws when calling an undefined macro', function () {
    $object = new MacroTestClass();
    expect(fn () => $object->undefinedMacro())
        ->toThrow(Exception::class, sprintf(
            'Method %s::undefinedMacro does not exist.',
            MacroTestClass::class
        ));
});
