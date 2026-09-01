<?php

declare(strict_types=1);

use Infocyph\InterMix\Remix\MacroMix;

class MacroTestClass
{
    use MacroMix;

    public const ENABLE_LOCK = true;

    public string $name = '';
}

class UnlockedMacroTestClass
{
    use MacroMix;

    public const ENABLE_LOCK = false;
}

class StaticMacroMixin
{
    public static function ping(): string
    {
        return 'pong';
    }
}

it('registers & calls macros', function () {
    MacroTestClass::macro('sayHello', static fn () => 'Hello, MacroMix!');
    expect(MacroTestClass::sayHello())->toBe('Hello, MacroMix!');
});

it('detects & removes macros', function () {
    MacroTestClass::macro('sayGoodbye', static fn () => 'Goodbye, MacroMix!');
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
    MacroTestClass::mix(StaticMacroMixin::class);

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

it('uses the direct mutation path when locking is disabled', function () {
    UnlockedMacroTestClass::macro('direct', static fn(): string => 'ok');
    UnlockedMacroTestClass::loadMacrosFromConfig([
        'configured' => static fn(): string => 'configured',
    ]);

    expect(UnlockedMacroTestClass::direct())->toBe('ok')
        ->and(UnlockedMacroTestClass::configured())->toBe('configured');

    UnlockedMacroTestClass::removeMacro('direct');
    expect(UnlockedMacroTestClass::hasMacro('direct'))->toBeFalse();
});

it('protects direct and bulk mutations when locking is enabled', function () {
    MacroTestClass::macro('lockedDirect', static fn(): int => 1);
    MacroTestClass::loadMacrosFromConfig([
        'lockedA' => static fn(): int => 2,
        'lockedB' => static fn(): int => 3,
    ]);

    expect(MacroTestClass::lockedDirect())->toBe(1)
        ->and(MacroTestClass::lockedA())->toBe(2)
        ->and(MacroTestClass::lockedB())->toBe(3);

    MacroTestClass::removeMacro('lockedDirect');
    expect(MacroTestClass::hasMacro('lockedDirect'))->toBeFalse();
});

it('releases the mutation lock when an operation throws', function () {
    $mutate = new ReflectionMethod(MacroTestClass::class, 'mutate');

    expect(fn() => $mutate->invoke(null, static fn() => throw new RuntimeException('failed')))
        ->toThrow(RuntimeException::class, 'failed');

    $path = sys_get_temp_dir() . '/intermix-locks/macro-' . hash('xxh128', MacroTestClass::class) . '.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to open test lock.');
    }

    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    flock($handle, LOCK_UN);
    fclose($handle);
});

it('keeps reads and macro execution lock-free', function () {
    MacroTestClass::macro('lockFreeRead', static fn(): string => 'read');
    $path = sys_get_temp_dir() . '/intermix-locks/macro-' . hash('xxh128', MacroTestClass::class) . '.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to open test lock.');
    }
    flock($handle, LOCK_EX);

    try {
        expect(MacroTestClass::hasMacro('lockFreeRead'))->toBeTrue()
            ->and(MacroTestClass::getMacros())->toHaveKey('lockFreeRead')
            ->and(MacroTestClass::lockFreeRead())->toBe('read');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});
