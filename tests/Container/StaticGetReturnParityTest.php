<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

class StaticGetReturnSingleton
{
    public bool $booted = false;
    public function boot(): string { $this->booted = true; return 'singleton-return'; }
}
final class StaticGetReturnScoped extends StaticGetReturnSingleton {}
final class StaticGetReturnTransient extends StaticGetReturnSingleton {}

function staticGetReturnArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-get-return-' . bin2hex(random_bytes(8)) . '.php';
}
function removeStaticGetReturnArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) if (is_file($artifact)) unlink($artifact);
}

it('keeps retrieval and invocation explicit across dynamic and production runtimes', function () {
    $builder = ContainerBuilder::create(uniqid('explicit_return_'))
        ->autowire('singleton', StaticGetReturnSingleton::class)
        ->autowire('scoped', StaticGetReturnScoped::class, lifetime: LifetimeEnum::Scoped)
        ->autowire('transient', StaticGetReturnTransient::class, lifetime: LifetimeEnum::Transient);
    $path = staticGetReturnArtifactPath();

    try {
        $builder->compile($path);
        foreach ([$builder->build(), $builder->production($path)] as $runtime) {
            expect(method_exists($runtime, 'getReturn'))->toBeFalse();
            $runtime->withinScope('request', static function (RuntimeContainerInterface $active): void {
                $singleton = $active->get('singleton');
                $scoped = $active->get('scoped');
                $transient = $active->get('transient');

                expect($singleton->booted)->toBeFalse()
                    ->and($scoped->booted)->toBeFalse()
                    ->and($transient->booted)->toBeFalse()
                    ->and($active->invoke([$singleton, 'boot']))->toBe('singleton-return')
                    ->and($active->invoke([$scoped, 'boot']))->toBe('singleton-return')
                    ->and($active->invoke([$transient, 'boot']))->toBe('singleton-return')
                    ->and($active->get('singleton'))->toBe($singleton)
                    ->and($active->get('scoped'))->toBe($scoped)
                    ->and($active->get('transient'))->not->toBe($transient);
            });
        }
    } finally {
        removeStaticGetReturnArtifact($path);
    }
});
