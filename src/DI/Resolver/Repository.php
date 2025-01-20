<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\Exceptions\ContainerException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Central storage for the container’s definitions, resolved instances, etc.
 * Also includes optional toggles for:
 *   - environment-based overrides
 *   - lazy loading
 *   - debug mode
 *   - unified cache key generation
 */
class Repository
{
    /**
     * Definitions
     *  - functionReference: [id => definition]
     *  - classResource: [className => ['constructor'=>..., 'method'=>..., 'property'=>...]]
     *  - closureResource: [alias => [...]]
     *  - resolved: [id => resolved instance]
     *  - resolvedResource: [className => [...]]
     *  - resolvedDefinition: [defName => definitionValue]
     */
    private array $functionReference   = [];
    private array $classResource       = [];
    private array $closureResource     = [];
    private array $resolved            = [];
    private array $resolvedResource    = [];
    private array $resolvedDefinition  = [];

    /** Additional arrays if you want environment or conditional logic */
    private array $conditionalBindings = []; // e.g. [envName => [someInterface => someConcrete]]

    /**
     * Toggles and states
     */
    private ?string $defaultMethod           = null;
    private bool $enablePropertyAttribute    = false;
    private bool $enableMethodAttribute      = false;
    private bool $isLocked                   = false;
    private ?CacheInterface $cacheAdapter    = null;
    private string $alias                    = 'default';

    /**
     * (Optional) environment-based overrides
     */
    private ?string $environment = null;

    /**
     * If true, some definitions or services can be "lazy"
     * and not resolved until explicitly requested the first time.
     */
    private bool $lazyLoading = false;

    /**
     * If true, we can log or store debug messages for diagnostic.
     */
    private bool $debug = false;

    /**
     * Check if the container is locked (throws if yes).
     *
     * @throws ContainerException
     */
    private function checkIfLocked(): void
    {
        if ($this->isLocked) {
            throw new ContainerException('Container is locked! Unable to set/modify any value.');
        }
    }

    /* ------------------------------------------------------------------------
     |   Locking, environment, debug, lazy toggles
     * ----------------------------------------------------------------------*/

    /**
     * Lock the repository from future modifications.
     */
    public function lock(): void
    {
        $this->isLocked = true;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    /**
     * Set the environment name (e.g. 'production', 'local', etc.).
     */
    public function setEnvironment(string $env): void
    {
        $this->checkIfLocked();
        $this->environment = $env;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Enable or disable debug mode.
     */
    public function setDebug(bool $enabled): void
    {
        $this->debug = $enabled;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Enable or disable lazy loading system-wide.
     */
    public function enableLazyLoading(bool $lazy): void
    {
        $this->checkIfLocked();
        $this->lazyLoading = $lazy;
    }

    public function isLazyLoading(): bool
    {
        return $this->lazyLoading;
    }

    /* ------------------------------------------------------------------------
     |   Environment-based / conditional binding
     * ----------------------------------------------------------------------*/

    /**
     * Add a conditional binding for a given environment:
     * e.g. bindInterfaceForEnv('production', LoggerInterface::class, ProductionLogger::class)
     */
    public function bindInterfaceForEnv(string $env, string $interface, string $concrete): void
    {
        $this->checkIfLocked();
        $this->conditionalBindings[$env][$interface] = $concrete;
    }

    /**
     * Resolve environment-based interface mapping if set.
     * Return null if no match found for current environment.
     */
    public function getEnvConcrete(?string $interface): ?string
    {
        if (! $this->environment || ! $interface) {
            return null;
        }
        return $this->conditionalBindings[$this->environment][$interface] ?? null;
    }

    /* ------------------------------------------------------------------------
     |   Public getters/setters for main arrays
     * ----------------------------------------------------------------------*/

    public function hasFunctionReference(string $id): bool
    {
        return array_key_exists($id, $this->functionReference);
    }


    public function getFunctionReference(): array
    {
        return $this->functionReference;
    }

    public function setFunctionReference(string $id, mixed $definition): void
    {
        $this->checkIfLocked();
        $this->functionReference[$id] = $definition;
    }

    public function getClassResource(): array
    {
        return $this->classResource;
    }

    /**
     * addClassResource($className, 'constructor', ['on'=>'__constructor','params'=>[...]])
     */
    public function addClassResource(string $class, string $key, array $data): void
    {
        $this->checkIfLocked();
        $this->classResource[$class][$key] = $data;
    }

    public function getClosureResource(): array
    {
        return $this->closureResource;
    }

    /**
     * store closure resource:
     *   closureAlias => ['on'=>$function, 'params'=>$params]
     */
    public function addClosureResource(string $alias, callable $function, array $params = []): void
    {
        $this->checkIfLocked();
        $this->closureResource[$alias] = ['on' => $function, 'params' => $params];
    }

    public function getResolved(): array
    {
        return $this->resolved;
    }

    /**
     * For ID-based resolutions
     */
    public function setResolved(string $id, mixed $value): void
    {
        // no lock check needed typically, but you can do it if you want
        $this->resolved[$id] = $value;
    }

    public function getResolvedResource(): array
    {
        return $this->resolvedResource;
    }

    /**
     * For class-based "resource" resolution:  resolvedResource[$className] = [...]
     */
    public function setResolvedResource(string $className, array $data): void
    {
        $this->resolvedResource[$className] = $data;
    }

    public function getResolvedDefinition(): array
    {
        return $this->resolvedDefinition;
    }

    public function setResolvedDefinition(string $defName, mixed $value): void
    {
        $this->resolvedDefinition[$defName] = $value;
    }

    /* ------------------------------------------------------------------------
     |   Default method, property/method attribute toggles
     * ----------------------------------------------------------------------*/

    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    public function setDefaultMethod(?string $method): void
    {
        $this->checkIfLocked();
        $this->defaultMethod = $method;
    }

    public function isPropertyAttributeEnabled(): bool
    {
        return $this->enablePropertyAttribute;
    }

    public function enablePropertyAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        $this->enablePropertyAttribute = $enable;
    }

    public function isMethodAttributeEnabled(): bool
    {
        return $this->enableMethodAttribute;
    }

    public function enableMethodAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        $this->enableMethodAttribute = $enable;
    }

    /* ------------------------------------------------------------------------
     |   Caching (Symfony Cache)
     * ----------------------------------------------------------------------*/

    public function getCacheAdapter(): ?CacheInterface
    {
        return $this->cacheAdapter;
    }

    public function setCacheAdapter(CacheInterface $adapter): void
    {
        $this->checkIfLocked();
        $this->cacheAdapter = $adapter;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): void
    {
        $this->checkIfLocked();
        $this->alias = $alias;
    }

    /**
     * Helper to generate a consistent cache key for definitions or other container data.
     */
    public function makeCacheKey(string $suffix): string
    {
        return $this->alias . '-' . $suffix;
    }
}
