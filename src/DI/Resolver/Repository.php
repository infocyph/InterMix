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
    private bool $lazyLoading = true;

    /**
     * If true, we can log or store debug messages for diagnostic.
     */
    private bool $debug = false;


    /**
     * Throw an exception if the container is locked and we try to set/modify values.
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
     * Locks the container from future modifications.
     *
     * Once the container is locked, no more definitions, values, or options can be set.
     * This method is useful for tests or other scenarios where you want to ensure that
     * the container does not change after it has been configured.
     *
     * @return void
     */
    public function lock(): void
    {
        $this->isLocked = true;
    }

    /**
     * Checks if the repository is locked from future modifications.
     *
     * @return bool if the repository is locked
     */
    public function isLocked(): bool
    {
        return $this->isLocked;
    }


    /**
     * Set the environment for this repository.
     *
     * The environment can be used to resolve environment-based interface mappings.
     * If the environment is set to a non-empty string, we will check if there is
     * a matching environment-based mapping for a given interface.
     *
     * @param string $env environment name
     *
     * @return void
     *
     * @throws ContainerException if the container is locked
     */
    public function setEnvironment(string $env): void
    {
        $this->checkIfLocked();
        $this->environment = $env;
    }

    /**
     * Retrieve the current environment.
     *
     * @return string|null the environment name, or null if not set
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }


    /**
     * Set the debug mode of the repository.
     *
     * If the debug mode is enabled, the repository will log debug information
     * about the resolution process. This can be useful for debugging purposes.
     *
     * @param bool $enabled whether to enable debug mode
     *
     * @return void
     */
    public function setDebug(bool $enabled): void
    {
        $this->debug = $enabled;
    }

    /**
     * Retrieve the current debug mode.
     *
     * @return bool whether debug mode is enabled
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }


    /**
     * Enables or disables lazy loading for the repository.
     *
     * If lazy loading is enabled, some definitions or services
     * can be "lazy" and not resolved until explicitly requested the first time.
     *
     * @param bool $lazy whether to enable lazy loading
     *
     * @return void
     *
     * @throws ContainerException if the container is locked
     */
    public function enableLazyLoading(bool $lazy): void
    {
        $this->checkIfLocked();
        $this->lazyLoading = $lazy;
    }

    /**
     * Checks if lazy loading is enabled for the repository.
     *
     * @return bool whether lazy loading is enabled
     */
    public function isLazyLoading(): bool
    {
        return $this->lazyLoading;
    }

    /* ------------------------------------------------------------------------
     |   Environment-based / conditional binding
     * ----------------------------------------------------------------------*/


    /**
     * Binds a concrete implementation to an interface for a specific environment.
     *
     * The given interface will be resolved to the given concrete implementation
     * only if the current environment matches the given environment.
     *
     * @param string $env the environment for which the binding should be applied
     * @param string $interface the interface to bind
     * @param string $concrete the concrete implementation to bind to
     *
     * @throws ContainerException if the container is locked
     */
    public function bindInterfaceForEnv(string $env, string $interface, string $concrete): void
    {
        $this->checkIfLocked();
        $this->conditionalBindings[$env][$interface] = $concrete;
    }


    /**
     * Get the concrete implementation bound to an interface for the current environment.
     *
     * This method will return the concrete implementation for the given interface
     * if the current environment matches the one set using `setEnvironment()`.
     * If there is no bound implementation for the current environment, or if the
     * environment is not set, this method will return null.
     *
     * @param string|null $interface the interface to get the concrete implementation for
     * @return string|null the concrete implementation for the given interface in the current environment
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

    /**
     * Checks if a function reference exists for the given identifier.
     *
     * This method determines whether a function reference is present in the
     * repository for the provided function identifier.
     *
     * @param string $id The identifier of the function reference.
     * @return bool True if the function reference exists, false otherwise.
     */
    public function hasFunctionReference(string $id): bool
    {
        return array_key_exists($id, $this->functionReference);
    }


    /**
     * Returns the array of function references.
     *
     * This method returns the array of function references, where each key is the
     * identifier of the function reference and the value is the definition of the
     * function reference.
     *
     * @return array the array of function references
     */
    public function getFunctionReference(): array
    {
        return $this->functionReference;
    }

    /**
     * Sets a function reference for the given identifier.
     *
     * This method assigns a function definition to an identifier in the
     * repository. It ensures that the container is not locked before
     * making modifications.
     *
     * @param string $id The identifier for the function reference.
     * @param mixed $definition The definition of the function reference.
     *
     * @throws ContainerException if the container is locked.
     */
    public function setFunctionReference(string $id, mixed $definition): void
    {
        $this->checkIfLocked();
        $this->functionReference[$id] = $definition;
    }

    /**
     * Retrieves the array of class resources.
     *
     * This method returns the array of class resources, where each key is the
     * class name and the value is an array containing constructor and method
     * data.
     *
     * @return array the array of class resources
     */
    public function getClassResource(): array
    {
        return $this->classResource;
    }


    /**
     * Stores a class resource, with a key that can be 'constructor', 'method', 'property'.
     *
     * The given data is stored in the class resources array as
     * $classResource[$class][$key] = $data.
     *
     *
     * This method ensures that the container is not locked before making modifications.
     *
     * @param string $class The class name.
     * @param string $key The key for the class resource.
     * @param array $data The data for the class resource.
     *
     * @throws ContainerException if the container is locked.
     */
    public function addClassResource(string $class, string $key, array $data): void
    {
        $this->checkIfLocked();
        $this->classResource[$class][$key] = $data;
    }

    /**
     * Retrieves the array of closure resources.
     *
     * This method returns the array of closure resources, where each key is the
     * alias of the closure and the value is an array containing the closure
     * function and its parameters.
     *
     * @return array the array of closure resources
     */
    public function getClosureResource(): array
    {
        return $this->closureResource;
    }


    /**
     * Adds a closure resource to the repository.
     *
     * This method stores a closure function with its associated alias and parameters
     * in the closure resources array. The closure can be later retrieved and executed
     * using its alias. Before adding the closure, it checks whether the container is
     * locked to prevent modifications.
     *
     * @param string $alias The alias for the closure.
     * @param callable $function The closure function to store.
     * @param array $params Optional parameters for the closure.
     *
     * @throws ContainerException if the container is locked.
     */
    public function addClosureResource(string $alias, callable $function, array $params = []): void
    {
        $this->checkIfLocked();
        $this->closureResource[$alias] = ['on' => $function, 'params' => $params];
    }

    /**
     * Returns the array of resolved resources.
     *
     * This method returns the array of resolved resources, where each key is the
     * ID of the resource and the value is the resolved value (instance, closure, etc.).
     *
     * @return array the array of resolved resources
     */
    public function getResolved(): array
    {
        return $this->resolved;
    }


    /**
     * Stores a resolved resource with its ID in the "resolved" array.
     *
     * This method is used to store the resolved value of a resource in the
     * repository. The resolved value is associated with the resource ID and
     * can be later retrieved using the same ID. The container is not checked
     * for locks before storing the value, so use with caution.
     *
     * @param string $id The ID of the resource.
     * @param mixed $value The resolved value of the resource.
     */
    public function setResolved(string $id, mixed $value): void
    {
        // no lock check needed typically, but you can do it if you want
        $this->resolved[$id] = $value;
    }

    /**
     * Returns the array of resolved resources for class-based resources.
     *
     * This method returns the array of resolved resources, where each key is the
     * class name and the value is an array containing the resolved values for
     * that class, such as the instance, constructor, properties, and methods.
     *
     * @return array the array of resolved resources for class-based resources
     */
    public function getResolvedResource(): array
    {
        return $this->resolvedResource;
    }



    /**
     * Stores a resolved resource for a class-based resource.
     *
     * This method takes the class name and an array of resolved values for
     * that class, such as the instance, constructor, properties, and methods.
     * The array is stored in the "resolvedResource" array with the class name
     * as the key.
     *
     * @param string $className The class name of the resource.
     * @param array $data The array of resolved values for the class.
     */
    public function setResolvedResource(string $className, array $data): void
    {
        $this->resolvedResource[$className] = $data;
    }

    /**
     * Returns the array of resolved definitions.
     *
     * This method returns the array of resolved definitions, where each key is the
     * definition name and the value is the resolved value of that definition.
     *
     * @return array the array of resolved definitions
     */
    public function getResolvedDefinition(): array
    {
        return $this->resolvedDefinition;
    }

    /**
     * Stores a resolved definition value by name.
     *
     * This method takes the name of a definition and a value, and stores the
     * value in the "resolvedDefinition" array with the definition name as the
     * key.
     *
     * @param string $defName The name of the definition to store.
     * @param mixed $value The value to store.
     */
    public function setResolvedDefinition(string $defName, mixed $value): void
    {
        $this->resolvedDefinition[$defName] = $value;
    }

    /* ------------------------------------------------------------------------
     |   Default method, property/method attribute toggles
     * ----------------------------------------------------------------------*/

    /**
     * Retrieves the default method to be called when resolving a class.
     *
     * Returns the default method name that is used when resolving a class
     * resource. If no default method is set, this method returns null.
     *
     * @return string|null the default method name, or null if no default is set
     */
    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    /**
     * Sets the default method to be called when resolving a class.
     *
     * This method assigns a new default method name to be used when resolving
     * class resources. If the container is locked, an exception will be thrown.
     *
     * @param string|null $method The default method name, or null to unset.
     *
     * @throws ContainerException if the container is locked.
     */
    public function setDefaultMethod(?string $method): void
    {
        $this->checkIfLocked();
        $this->defaultMethod = $method;
    }

    /**
     * Returns whether property attributes are enabled.
     *
     * Property attributes are an InterMix feature that allows you to inject
     * values into class properties. This method returns true if property
     * attributes are enabled, and false otherwise.
     *
     * @return bool true if property attributes are enabled, false otherwise
     */
    public function isPropertyAttributeEnabled(): bool
    {
        return $this->enablePropertyAttribute;
    }

    /**
     * Enables or disables property attribute resolution.
     *
     * Property attributes are an InterMix feature that allows you to inject
     * values into class properties. This method enables or disables the
     * resolution of these attributes. If the container is locked, an exception
     * will be thrown.
     *
     * @param bool $enable true to enable property attribute resolution, false to disable
     *
     * @throws ContainerException if the container is locked.
     */
    public function enablePropertyAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        $this->enablePropertyAttribute = $enable;
    }

    /**
     * Returns whether method attributes are enabled.
     *
     * Method attributes are an InterMix feature that allows you to inject
     * values into class methods. This method returns true if method
     * attributes are enabled, and false otherwise.
     *
     * @return bool true if method attributes are enabled, false otherwise
     */
    public function isMethodAttributeEnabled(): bool
    {
        return $this->enableMethodAttribute;
    }

    /**
     * Enables or disables method attribute resolution.
     *
     * Method attributes are an InterMix feature that allows you to inject
     * values into class methods. This method enables or disables the
     * resolution of these attributes. If the container is locked, an exception
     * will be thrown.
     *
     * @param bool $enable true to enable method attribute resolution, false to disable
     *
     * @throws ContainerException if the container is locked.
     */
    public function enableMethodAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        $this->enableMethodAttribute = $enable;
    }

    /* ------------------------------------------------------------------------
     |   Caching (Symfony Cache)
     * ----------------------------------------------------------------------*/

    /**
     * Gets the cache adapter instance.
     *
     * @return CacheInterface|null The cache adapter, or null if no cache adapter is set.
     */
    public function getCacheAdapter(): ?CacheInterface
    {
        return $this->cacheAdapter;
    }

    /**
     * Sets the cache adapter.
     *
     * This method sets the cache adapter instance to use for caching
     * definitions. If the container is locked, an exception will be
     * thrown.
     *
     * @param CacheInterface $adapter The cache adapter to set.
     *
     * @throws ContainerException if the container is locked.
     */
    public function setCacheAdapter(CacheInterface $adapter): void
    {
        $this->checkIfLocked();
        $this->cacheAdapter = $adapter;
    }

    /**
     * Returns the alias of this definition repository.
     *
     * The alias is a unique identifier for this repository. It is used to
     * generate cache keys and to identify the repository when serializing
     * or unserializing the container.
     *
     * @return string the alias of this repository
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Sets the alias of this definition repository.
     *
     * The alias is a unique identifier for this repository. It is used to
     * generate cache keys and to identify the repository when serializing
     * or unserializing the container.
     *
     * @param string $alias The alias to set.
     *
     * @throws ContainerException if the container is locked.
     */
    public function setAlias(string $alias): void
    {
        $this->checkIfLocked();
        $this->alias = $alias;
    }


    /**
     * Creates a cache key with the given suffix.
     *
     * The cache key is the result of concatenating the repository's alias
     * with the given suffix.
     *
     * @param string $suffix The suffix to use for the cache key.
     *
     * @return string The cache key.
     */
    public function makeCacheKey(string $suffix): string
    {
        return $this->alias . '-' . $suffix;
    }

    /**
     * If the given value is an array with an 'instance' key, returns the value of that key.
     * Otherwise, returns the given value.
     *
     * This method is used to extract the instance from a parameter definition.
     *
     * @param mixed $value The value to extract the instance from.
     *
     * @return mixed The instance or the original value.
     */
    public function fetchInstanceOrValue(mixed $value): mixed
    {
        return match(true) {
            is_array($value) && isset($value['instance']) => $value['instance'],
            default => $value
        };
    }
}
