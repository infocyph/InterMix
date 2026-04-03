<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    private const int PROPERTY_PLAN_CACHE_LIMIT = 1024;

    private ClassResolver $classResolver;
    /** @var array<string, array> */
    private array $propertyPlanCache = [];

    /**
     * Constructs a PropertyResolver instance.
     *
     * @param Repository $repository The Repository providing definitions, classes, functions, and parameters.
     */
    public function __construct(
        private readonly Repository $repository,
    ) {
    }


    /**
     * Resolve any properties for the given class (if instance is already resolved).
     * If no instance, does nothing.
     *
     * First, resolve any public properties of the class.
     * Then, resolve any private properties of the parent class.
     * Finally, mark the property resolution as complete in the repository.
     *
     * @param ReflectionClass $class The class to resolve properties for.
     * @throws ContainerException|ReflectionException
     * @throws InvalidArgumentException
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();
        $allResolved = $this->repository->getResolvedResourceFor($className);
        if (!isset($allResolved['instance'])) {
            return; // no instance => no property injection
        }

        $instance = $allResolved['instance'];
        $plan = $this->getPropertyPlan($class);

        $this->processProperties($class, $plan['classProperties'], $instance);

        if ($plan['parentClass'] instanceof ReflectionClass) {
            // handle parent private props
            $this->processProperties($plan['parentClass'], $plan['parentPrivateProperties'], $instance);
        }

        $allResolved['property'] = true;
        $this->repository->setResolvedResource($className, $allResolved);
    }

    /**
     * Called by Container to switch between InjectedCall & GenericCall, etc.
     *
     * @param ClassResolver $classResolver The new ClassResolver instance.
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }


    private function applyPropertyValue(
        ReflectionClass $class,
        ReflectionProperty $property,
        ?array $values,
    ): void {
        if ($values === [] || $values === null) {
            return;
        }

        if ($property->isStatic()) {
            $class->setStaticPropertyValue($property->getName(), $values[0]);
            return;
        }

        $property->setValue($values[0], $values[1]);
    }

    /**
     * Attempt to resolve a single property value using the built-in #[Infuse] attribute.
     *
     * @param ReflectionProperty $property The property to resolve a value for.
     * @param object $classInstance The instance of the class to set the property on.
     * @return ?array An array of two items: the instance and the resolved value. Or null if not possible to resolve.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function attemptBuiltInInfuse(
        ReflectionProperty $property,
        object $classInstance,
    ): ?array {
        if (!$this->repository->isPropertyAttributeEnabled()) {
            return null;
        }

        $attrs = $property->getAttributes(Infuse::class);
        if (!$attrs) {
            return null;
        }

        /** @var Infuse $infuse */
        $infuse = $attrs[0]->newInstance();

        // (a)  #[Infuse]   – no args  ➜  infer by type-hint
        if (empty($attrs[0]->getArguments())) {
            $val = $this->resolveWithoutArgument($property, $property->getType());
            return [$classInstance, $val];
        }

        // (b)  #[Infuse(...)] – has args ➜ delegate to ClassResolver
        $val = $this->classResolver->resolveInfuse($infuse);
        if ($val instanceof IMStdClass) {
            return null;
        }

        return $property->isStatic()
            ? [$val]
            : [$classInstance, $val];
    }

    /**
     * Attempts to resolve custom attributes for a given property.
     *
     * This method iterates over the attributes of the provided property
     * and checks if there is a registered resolver for each attribute.
     * If a resolver exists, it resolves the attribute value using the
     * AttributeRegistry. If a valid resolved value is obtained, it returns
     * an array containing either the resolved value alone (for static properties)
     * or the class instance and the resolved value (for non-static properties).
     * If no valid resolved value is found, it returns null.
     *
     * @param ReflectionProperty $property The property to resolve attributes for.
     * @param object $classInstance The instance of the class to set the property on.
     * @return ?array An array of two items: the instance (if non-static) and the resolved value, or null if not resolved.
     */
    private function attemptCustomAttributes(
        ReflectionProperty $property,
        object $classInstance,
    ): ?array {
        $injectVal = null;
        $handled = false;

        foreach ($property->getAttributes() as $raw) {
            $attrObj = $raw->newInstance();

            if (!$this->repository->attributeRegistry()->has($attrObj::class)) {
                continue;
            }

            $handled = true;
            $val = $this->repository->attributeRegistry()->resolve($attrObj, $property);

            if ($injectVal === null && $val !== null && !$val instanceof IMStdClass) {
                $injectVal = $val;
            }
        }

        if (!$handled) {
            return null;
        }

        if ($injectVal === null) {
            return [];
        }

        return $property->isStatic()
            ? [$injectVal]
            : [$classInstance, $injectVal];
    }

    /**
     * Attempt to resolve a single property value using the user-supplied values.
     *
     * Checks if the property is present in the user-supplied values and returns
     * an array containing the instance and the resolved value. If the property
     * is not present, returns null.
     *
     * @param ReflectionProperty $property The property to resolve a value for.
     * @param array $classPropertyValues The user-supplied values for the class.
     * @param object $classInstance The instance of the class to set the property on.
     * @return ?array An array of two items: the instance and the resolved value. Or null if not possible to resolve.
     */
    private function attemptUserOverride(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance,
    ): ?array {
        $name = $property->getName();

        if (!array_key_exists($name, $classPropertyValues)) {
            return null;
        }

        return $property->isStatic()
            ? [$classPropertyValues[$name]]
            : [$classInstance, $classPropertyValues[$name]];
    }

    private function getPropertyPlan(ReflectionClass $class): array
    {
        $className = $class->getName();
        if (array_key_exists($className, $this->propertyPlanCache)) {
            return $this->propertyPlanCache[$className];
        }

        $parent = $class->getParentClass();
        return $this->rememberPropertyPlan($className, [
            'classProperties' => $class->getProperties(),
            'parentClass' => $parent ?: null,
            'parentPrivateProperties' => $parent
                ? $parent->getProperties(ReflectionProperty::IS_PRIVATE)
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRegisteredProperties(string $className): ?array
    {
        $classResource = $this->repository->getClassResourceFor($className);
        return $classResource['property'] ?? null;
    }

    /**
     * Resolves any properties for the given class and instance.
     * Skips properties already set.
     *
     * @param ReflectionClass $class The class to resolve properties for.
     * @param array $properties The properties to resolve.
     * @param object $classInstance The instance of the class to set properties on.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function processProperties(
        ReflectionClass $class,
        array $properties,
        object $classInstance,
    ): void {
        if ($properties === []) {
            return;
        }

        $className = $class->getName();
        $registeredProps = $this->getRegisteredProperties($className);
        if ($this->shouldSkipPropertyResolution($registeredProps)) {
            return;
        }

        /** @var ReflectionProperty $property */
        foreach ($properties as $property) {
            if ($this->shouldSkipProperty($property, $registeredProps)) {
                continue;
            }

            $this->tracePropertyResolution($property, $className);
            $values = $this->resolveValue($property, $registeredProps ?? [], $classInstance);
            $this->applyPropertyValue($class, $property, $values);
        }
    }

    private function rememberPropertyPlan(string $key, array $plan): array
    {
        if (!array_key_exists($key, $this->propertyPlanCache)
            && count($this->propertyPlanCache) >= self::PROPERTY_PLAN_CACHE_LIMIT
        ) {
            $oldest = array_key_first($this->propertyPlanCache);
            if ($oldest !== null) {
                unset($this->propertyPlanCache[$oldest]);
            }
        }

        $this->propertyPlanCache[$key] = $plan;
        return $plan;
    }

    /**
     * Resolve a single property value.
     *
     * 1) User-supplied values have priority.
     * 2) If not user-supplied, then attributes are checked.
     * 3) If no attribute, then return an empty array.
     *
     * @param ReflectionProperty $property The property to resolve a value for.
     * @param array $classPropertyValues The user-supplied values for the class.
     * @param object $classInstance The instance of the class to set the property on.
     * @return ?array An array of two items: the instance and the resolved value. Or null if not possible to resolve.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveValue(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance,
    ): ?array {
        $attempt = $this->attemptUserOverride($property, $classPropertyValues, $classInstance);
        if ($attempt !== null) {
            return $attempt;
        }

        $attempt = $this->attemptBuiltInInfuse($property, $classInstance);
        if ($attempt !== null) {
            return $attempt;
        }

        $attempt = $this->attemptCustomAttributes($property, $classInstance);
        if ($attempt !== null) {
            return $attempt;
        }

        return [];
    }

    /**
     * Resolve a property without an argument.
     *
     * If the property has a `#[Infuse]` attribute with no arguments, this method
     * is called to resolve the value. It will throw a
     * `ContainerException` if the property type is not a class or interface.
     * If the type is an interface, it will check for an environment-based
     * override before resolving the class.
     *
     * @param ReflectionProperty $property The property to resolve.
     * @param ReflectionType|null $parameterType The type of the property.
     *
     * @return object The resolved value.
     *
     * @throws ContainerException|ReflectionException
     */
    private function resolveWithoutArgument(
        ReflectionProperty $property,
        ?ReflectionType $parameterType,
    ): object {
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                'Malformed #[Infuse] or invalid property type on ' .
                "{$property->getDeclaringClass()->getName()}::\${$property->getName()}",
            );
        }
        // environment-based override if interface
        $className = $parameterType->getName();
        if (interface_exists($className)) {
            $envConcrete = $this->repository->getEnvConcrete($className);
            $className = $envConcrete ?: $className;
        }
        $refClass = ReflectionResource::getClassReflection($className);

        if (interface_exists($parameterType->getName())
            && !$refClass->implementsInterface($parameterType->getName())
        ) {
            throw new ContainerException(
                "$className does not implement {$parameterType->getName()} (environment override mismatch)",
            );
        }

        return $this->classResolver->resolve($refClass)['instance'];
    }

    private function shouldSkipProperty(ReflectionProperty $property, ?array $registeredProps): bool
    {
        return $property->isPromoted()
            && !isset(($registeredProps ?? [])[$property->getName()])
            && empty($property->getAttributes(Infuse::class));
    }

    private function shouldSkipPropertyResolution(?array $registeredProps): bool
    {
        return $registeredProps === null && !$this->repository->isPropertyAttributeEnabled();
    }

    private function tracePropertyResolution(ReflectionProperty $property, string $className): void
    {
        $this->repository->tracer()->push(
            "prop {$property->getName()} of $className",
            TraceLevelEnum::Verbose,
        );
    }
}
