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
use Infocyph\InterMix\Exceptions\ContainerException;

final class ContainerBuilder
{
    private readonly Container $development;

    /** @var null|array{compiled: list<string>, skipped: array<string, string>, sha256: string} */
    private ?array $compilationReport = null;

    /** @var array<string, true> */
    private array $dynamicServiceIds = [];

    private bool $finalizedArtifactExists = false;

    /** @var list<ProductionContainer> */
    private array $productionRuntimes = [];

    private bool $requiresCompilation = false;

    /** @var array<string, true> */
    private array $resolvedHookIds = [];

    /** @var array<string, true> */
    private array $resolvingHookIds = [];

    /** @var array<string, true> */
    private array $scopeLeaveHookScopes = [];

    private bool $suppressMutationListener = false;

    public function __construct(?Container $development = null)
    {
        $this->development = $development ?? new Container();
        $this->development->getRepository()->setConfigurationMutationListener(
            function (): void {
                $this->repositoryMutated();
            },
        );
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
        $this->beforeMutation();
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
        $this->beforeMutation();
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
        $this->beforeMutation();
        $this->development->bindFactory($id, $factory, $lifetime, $tags);

        return $this;
    }

    /** @return null|array{compiled: list<string>, skipped: array<string, string>, sha256: string} */
    public function compilationReport(): ?array
    {
        return $this->compilationReport;
    }

    /** @return array{compiled: list<string>, skipped: array<string, string>, sha256: string} */
    public function compile(string $path): array
    {
        $this->beforeMutation();
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from(
                $this->development->getRepository(),
                array_keys($this->dynamicServiceIds),
                array_keys($this->resolvingHookIds),
                array_keys($this->resolvedHookIds),
                array_keys($this->scopeLeaveHookScopes),
            ),
            $path,
        );
        $this->compilationReport = [
            'compiled' => $generated['compiled'],
            'skipped' => $generated['skipped'],
            'sha256' => $generated['sha256'],
        ];
        $this->finalizedArtifactExists = true;
        $this->requiresCompilation = false;

        return $this->compilationReport;
    }

    public function definitions(): DefinitionManager
    {
        $this->beforeMutation();

        return $this->development->definitions();
    }

    public function development(): Container
    {
        $this->beforeMutation();

        return $this->development;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->beforeMutation();
        $this->development->enableLazyLoading($lazy);

        return $this;
    }

    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        $this->beforeMutation();

        return $this->development->factory($id, $factory);
    }

    public function onMissing(callable $callback): self
    {
        $this->beforeMutation();
        $this->development->onMissing($callback);

        return $this;
    }

    public function onResolved(string $id, callable $callback): self
    {
        $this->beforeMutation();
        $this->resolvedHookIds[$id] = true;
        $this->development->onResolved($id, $callback);

        return $this;
    }

    public function onResolving(string $id, callable $callback): self
    {
        $this->beforeMutation();
        $this->resolvingHookIds[$id] = true;
        $this->development->onResolving($id, $callback);

        return $this;
    }

    public function onScopeLeave(string $scope, callable $callback): self
    {
        $this->beforeMutation();
        $this->scopeLeaveHookScopes[$scope] = true;
        $this->development->onScopeLeave($scope, $callback);

        return $this;
    }

    public function options(): OptionsManager
    {
        $this->beforeMutation();

        return $this->development->options();
    }

    public function production(string $path): ProductionContainer
    {
        $this->beforeProductionLoad();

        return $this->withoutMutationNotifications(
            fn(): ProductionContainer => $this->rememberProductionRuntime(
                new StaticRuntimeGenerator()->load($path, $this->development),
            ),
        );
    }

    public function productionPrevalidated(string $path, string $sha256): ProductionContainer
    {
        $this->beforeProductionLoad();

        return $this->withoutMutationNotifications(
            fn(): ProductionContainer => $this->rememberProductionRuntime(
                new StaticRuntimeGenerator()->loadPrevalidated(
                    $path,
                    $sha256,
                    $this->development,
                ),
            ),
        );
    }

    public function registration(): RegistrationManager
    {
        $this->beforeMutation();

        return $this->development->registration();
    }

    /** @param array<int, string> $tags */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->beforeMutation();
        $this->development->scoped($id, $definition, $tags);

        return $this;
    }

    public function setEnvironment(string $environment): self
    {
        $this->beforeMutation();
        $this->development->setEnvironment($environment);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->beforeMutation();
        $this->development->singleton($id, $definition, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->beforeMutation();
        $this->development->transient($id, $definition, $tags);

        return $this;
    }

    public function unbind(string $id): self
    {
        $this->beforeMutation();
        $this->development->unbind($id);
        unset($this->dynamicServiceIds[$id]);

        return $this;
    }

    /** @return array<int, string> */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        $this->beforeMutation();

        return $this->development->validate($strict, $resolveFactories);
    }

    public function value(string $id, mixed $value): self
    {
        $this->beforeMutation();
        $this->development->value($id, $value);

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        $this->beforeMutation();

        return $this->development->when($consumer);
    }

    private function beforeMutation(): void
    {
        if ($this->finalizedArtifactExists) {
            $this->compilationReport = null;
            $this->requiresCompilation = true;
        }

        $this->deoptimizeProductionRuntimes();
    }

    private function beforeProductionLoad(): void
    {
        if ($this->requiresCompilation) {
            throw new ContainerException(
                'The static runtime must be recompiled after builder configuration mutation.',
            );
        }

        $this->deoptimizeProductionRuntimes();
    }

    private function deoptimizeProductionRuntimes(): void
    {
        if ($this->productionRuntimes === []) {
            return;
        }

        $this->withoutMutationNotifications(function (): void {
            foreach ($this->productionRuntimes as $runtime) {
                $runtime->deoptimize();
            }
        });
        $this->productionRuntimes = [];
    }

    private function rememberProductionRuntime(ProductionContainer $runtime): ProductionContainer
    {
        $this->productionRuntimes[] = $runtime;
        $this->finalizedArtifactExists = true;

        return $runtime;
    }

    private function repositoryMutated(): void
    {
        if ($this->suppressMutationListener) {
            return;
        }
        if ($this->finalizedArtifactExists) {
            $this->compilationReport = null;
            $this->requiresCompilation = true;
        }

        $this->deoptimizeProductionRuntimes();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutMutationNotifications(callable $callback): mixed
    {
        $previous = $this->suppressMutationListener;
        $this->suppressMutationListener = true;

        try {
            return $callback();
        } finally {
            $this->suppressMutationListener = $previous;
        }
    }
}
