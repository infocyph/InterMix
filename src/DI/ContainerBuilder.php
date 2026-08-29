<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PendingFactoryBinding;

final class ContainerBuilder
{
    private readonly Container $development;

    /** @var null|array{compiled: list<string>, skipped: array<string, string>} */
    private ?array $compilationReport = null;

    /** @var array<string, true> */
    private array $dynamicServiceIds = [];

    public function __construct(?Container $development = null)
    {
        $this->development = $development ?? new Container();
    }

    public static function create(string $alias = Container::DEFAULT_ALIAS): self
    {
        return new self(new Container($alias));
    }

    public function alias(
        string $id,
        string $target,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
    ): self {
        $this->development->bind($id, new AliasDefinition($target), $lifetime);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->development->bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->development->bindFactory($id, $factory, $lifetime, $tags);

        return $this;
    }

    /** @return array{compiled: list<string>, skipped: array<string, string>} */
    public function compile(string $path): array
    {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from(
                $this->development->getRepository(),
                array_keys($this->dynamicServiceIds),
            ),
            $path,
        );
        $this->compilationReport = [
            'compiled' => $generated['compiled'],
            'skipped' => $generated['skipped'],
        ];

        return $this->compilationReport;
    }

    /** @return null|array{compiled: list<string>, skipped: array<string, string>} */
    public function compilationReport(): ?array
    {
        return $this->compilationReport;
    }

    public function definitions(): DefinitionManager
    {
        return $this->development->definitions();
    }

    public function development(): Container
    {
        return $this->development;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->development->enableLazyLoading($lazy);

        return $this;
    }

    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return $this->development->factory($id, $factory);
    }

    public function onMissing(callable $callback): self
    {
        $this->development->onMissing($callback);

        return $this;
    }

    public function onResolved(string $id, callable $callback): self
    {
        $this->dynamicServiceIds[$id] = true;
        $this->development->onResolved($id, $callback);

        return $this;
    }

    public function onResolving(string $id, callable $callback): self
    {
        $this->dynamicServiceIds[$id] = true;
        $this->development->onResolving($id, $callback);

        return $this;
    }

    public function onScopeLeave(string $scope, callable $callback): self
    {
        $this->development->onScopeLeave($scope, $callback);

        return $this;
    }

    public function options(): OptionsManager
    {
        return $this->development->options();
    }

    public function production(string $path): ProductionContainer
    {
        return new StaticRuntimeGenerator()->load($path, $this->development);
    }

    public function registration(): RegistrationManager
    {
        return $this->development->registration();
    }

    /** @param array<int, string> $tags */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->development->scoped($id, $definition, $tags);

        return $this;
    }

    public function setEnvironment(string $environment): self
    {
        $this->development->setEnvironment($environment);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->development->singleton($id, $definition, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->development->transient($id, $definition, $tags);

        return $this;
    }

    public function unbind(string $id): self
    {
        $this->development->unbind($id);
        unset($this->dynamicServiceIds[$id]);

        return $this;
    }

    /** @return array<int, string> */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        return $this->development->validate($strict, $resolveFactories);
    }

    public function value(string $id, mixed $value): self
    {
        $this->development->value($id, $value);

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        return $this->development->when($consumer);
    }
}
