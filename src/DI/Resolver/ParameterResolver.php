<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Responsible for resolving function/method parameters for DI,
 * possibly logging debug info, and checking environment-based overrides.
 */
class ParameterResolver
{
    private ClassResolver $classResolver;
    private readonly IMStdClass $stdClass;
    private array $resolvedCache = [];
    private array $infuseCache = [];

    /**
     * @param Repository $repository The repository of definitions, classes, functions, and parameters.
     * @param DefinitionResolver $definitionResolver The resolver for definitions.
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly DefinitionResolver $definitionResolver
    ) {
        // Fallback placeholder for unresolvable references
        $this->stdClass = new IMStdClass();
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


    /**
     * Generates a unique cache key for a function/method resolution.
     *
     * Combines the function/method owner class, name, type, and a hash
     * of the supplied parameters to create a unique identifier.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object representing the function/method.
     * @param array $suppliedParameters The parameters supplied for the function/method call.
     * @param string $type A string indicating the type of operation or context.
     * @return string A unique cache key for the given function/method resolution.
     */
    private function makeResolutionCacheKey(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): string {
        $owner = $reflector->class ?? '';
        $argsHash = hash('xxh3', serialize($suppliedParameters));
        return "$owner::{$reflector->getName()}|$type|$argsHash";
    }


    /**
     * Retrieves the Infuse attributes for the given function/method reflection.
     *
     * The reflection attributes are cached by the function/method name to avoid
     * redundant lookups. The cache key is the fully qualified method name including
     * the class name if applicable.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @return array The Infuse attributes for the given function/method.
     */
    private function getInfuseAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $key = ($reflector->class ?? '').'::'.$reflector->getName();
        return $this->infuseCache[$key] ??= $reflector->getAttributes(Infuse::class);
    }


    /**
     * Resolves a definition based on the provided name and parameter type.
     *
     * This method attempts to resolve a definition using the provided name. If the name is found
     * in the function reference, it uses the definition resolver to resolve it. If the parameter
     * type is not a ReflectionNamedType, a fallback to a standard class (IMStdClass) is used.
     * Additionally, if the type name derived from the parameter is found in the function reference,
     * it resolves accordingly. Otherwise, it defaults to the standard class as a fallback.
     *
     * @param string $name The name of the definition or type to resolve.
     * @param ReflectionParameter $parameter The reflection of the parameter to be resolved.
     * @return mixed The resolved value or a fallback standard class if resolution is not possible.
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        // 1) Check if $name is in functionReference (like older logic)
        if ($this->repository->hasFunctionReference($name)) {
            return $this->definitionResolver->resolve($name);
        }

        // 2) If type is not ReflectionNamedType => fallback to stdClass
        if (! $parameterType instanceof ReflectionNamedType) {
            return $this->stdClass;
        }

        $typeName = $parameterType->getName();

        // 3) If typeName is in functionReference => resolve that
        if ($this->repository->hasFunctionReference($typeName)) {
            return $this->definitionResolver->resolve($typeName);
        }

        // 4) Otherwise fallback to stdClass
        return $this->stdClass;
    }


    /**
     * Resolves the parameters for a given function/method by combining
     * supplied parameters with Infuse attributes from the method.
     *
     * The method takes a ReflectionFunctionAbstract object, an array of
     * supplied parameters, and a string indicating the type of operation
     * or context. The method first checks for a cache hit, and if not,
     * resolves the parameters using the following steps:
     * 1) Resolve associative parameters
     * 2) Resolve numeric/default/variadic parameters
     * 3) If a variadic parameter is present, process it
     *
     * The method returns an array of resolved parameters.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @param array $suppliedParameters The parameters supplied for the function/method call.
     * @param string $type A string indicating the type of operation or context.
     * @return array An array of resolved parameters.
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $availableParams = $reflector->getParameters();
        $applyAttribute  = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor ($reflector->class ?? null));

        // if method-level Infuse attributes exist
        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes(
                $this->getInfuseAttributes($reflector)
            );
        }

        // 1) Resolve associative params
        [
            'availableParams' => $paramsLeft,
            'processed'       => $processed,
            'availableSupply' => $availableSupply,
            'sort'            => $sort
        ] = $this->resolveAssociativeParameters(
            $reflector,
            $availableParams,
            $type,
            $suppliedParameters,
            $attributeData
        );

        // 2) Resolve numeric/default/variadic
        [
            'processed' => $numProcessed,
            'variadic'  => $variadic
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $paramsLeft,
            $availableSupply,
            $applyAttribute
        );

        $processed += $numProcessed;

        // 3) If we have a variadic param
        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        return $this->resolvedCache[$cacheKey] = $processed;
    }

    /**
     * Resolve Infuse attributes set on a method and return the first one's method arguments.
     *
     * @param array $attributes Infuse attributes set on a method
     * @return array The method arguments of the first attribute
     */
    private function resolveMethodAttributes(array $attributes): array
    {
        if (!$attributes || empty($attributes[0]->getArguments())) {
            return [];
        }
        // e.g. $attributes[0]->newInstance()->getMethodData()
        return $attributes[0]->newInstance()->getMethodArguments();
    }


    /**
     * Resolves associative parameters for a given function/method.
     *
     * Takes a ReflectionFunctionAbstract object, an array of available parameters,
     * a string indicating the type of operation or context, an array of supplied
     * parameters, and an array of Infuse attributes set on the method.
     *
     * The method iterates over the available parameters and tries to resolve
     * each one using the following steps:
     * 1) If the parameter is variadic, break the loop and store it in $paramsLeft
     * 2) Resolve the parameter using supplied parameters and Infuse attributes
     * 3) If the parameter is resolved, add it to the $processed array
     * 4) If not, add it to the $paramsLeft array
     *
     * The method returns an array containing the following:
     * - 'availableParams': an array of parameters that were not resolved
     * - 'processed': an array of resolved parameters
     * - 'availableSupply': an array of supplied parameters that were not used
     * - 'sort': an array of parameter names sorted by their position
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @param array $availableParams An array of available parameters.
     * @param string $type A string indicating the type of operation or context.
     * @param array $suppliedParameters An array of supplied parameters.
     * @param array $parameterAttribute An array of Infuse attributes set on the method.
     * @return array An array containing the resolved associative parameters.
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute
    ): array {
        $processed  = [];
        $paramsLeft = [];
        $sort       = [];

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();
            $sort[$paramName] = $key;

            if ($param->isVariadic()) {
                $paramsLeft[] = $param;
                break;
            }

            $resolvedValue = $this->tryResolveAssociative(
                $reflector,
                $param,
                $type,
                $suppliedParameters,
                $parameterAttribute,
                $processed
            );

            if ($resolvedValue !== $this->stdClass) {
                $processed[$paramName] = $resolvedValue;
            } else {
                $paramsLeft[] = $param;
            }
        }

        $lastKey = array_key_last($paramsLeft);
        $useNumeric = ($lastKey !== null && $paramsLeft[$lastKey]->isVariadic())
            ? array_diff_key($suppliedParameters, $processed)
            : array_filter($suppliedParameters, 'is_int', ARRAY_FILTER_USE_KEY);

        return [
            'availableParams' => $paramsLeft,
            'processed'       => $processed,
            'availableSupply' => $useNumeric,
            'sort'            => $sort
        ];
    }

    /**
     * Attempts to resolve a parameter associatively using various strategies.
     *
     * This method processes a parameter for a given function/method by trying to
     * resolve it using the following strategies:
     * 1) Resolve by definition reference if available.
     * 2) Attempt an environment-based resolution if the parameter type is an interface.
     * 3) Use the explicitly supplied parameter value if it exists.
     * 4) Resolve using method-level attributes if provided.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @param ReflectionParameter $param The parameter to be resolved.
     * @param string $type The type of operation or context.
     * @param array $suppliedParameters An array of supplied parameters.
     * @param array $parameterAttribute An array of attributes set on the method.
     * @param array $processed An array of parameters that have already been processed.
     * @return mixed The resolved parameter value or a default value if resolution fails.
     * @throws ContainerException|ReflectionException
     */
    private function tryResolveAssociative(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $param,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
        array $processed
    ): mixed {
        $paramName = $param->getName();

        // 1) By definition reference
        $definition = $this->resolveByDefinitionType($paramName, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        // 2) Possibly environment-based interface => concrete
        $classReflection = $this->getResolvableReflection($reflector, $param, $type, $processed);
        if ($classReflection) {
            $nameHint = $classReflection->isInterface()
                ? $classReflection->getName()
                : $paramName;

            return $this->resolveClassDependency(
                $classReflection,
                $type,
                $suppliedParameters[$nameHint] ?? $suppliedParameters[$paramName] ?? null
            );
        }

        // 3) If explicitly in suppliedParameters
        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        // 4) Method-level attribute array
        if (isset($parameterAttribute[$paramName])) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== $this->stdClass) {
                return $resolved;
            }
        }

        return $this->stdClass;
    }

    /**
     * Resolve numeric/default/variadic parameters using the supplied parameters.
     *
     * The method processes the parameters for a given function/method by iterating
     * over the available parameters and attempting to resolve them using the
     * following strategies:
     * 1) If the parameter is variadic, store it.
     * 2) If the parameter is present in the supplied parameters, use it.
     * 3) If method-level attributes are enabled, use the attribute to resolve the parameter.
     * 4) Use the default value if available, or null if the parameter allows null.
     * 5) If no other strategy works, throw a ContainerException.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @param array $availableParams An array of available parameters.
     * @param array $suppliedParameters An array of supplied parameters.
     * @param bool $applyAttribute A boolean indicating whether to apply method-level attributes.
     * @return array An array containing the resolved numeric/default/variadic parameters.
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveNumericDefaultParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        array $suppliedParameters,
        bool $applyAttribute
    ): array {
        $processed = [];
        $variadic  = ['type' => null, 'value' => null];
        $sequential = array_values($suppliedParameters);

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();

            if ($param->isVariadic()) {
                $variadic = [
                    'type'  => $param->getType() instanceof ReflectionNamedType
                        ? $param->getType()
                        : null,
                    'value' => array_slice($suppliedParameters, $key),
                ];
                break;
            }

            if (array_key_exists($key, $sequential)) {
                $processed[$paramName] = $sequential[$key];
                continue;
            }

            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($param);
                if ($data['isResolved']) {
                    $processed[$paramName] = $data['resolved'];
                    continue;
                }
            }

            // fallback to default or null
            $processed[$paramName] = match (true) {
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                $param->allowsNull() => null,
                default => throw new ContainerException(
                    "Resolution failed for '$paramName' in ".
                    ($reflector->class ?? $reflector->getName()).
                    "::{$reflector->getShortName()}()"
                )
            };
        }

        return [
            'processed' => $processed,
            'variadic'  => $variadic
        ];
    }

    /**
     * Get a ReflectionClass for the given parameter if it's resolvable.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function/method.
     * @param ReflectionParameter $parameter The parameter to resolve.
     * @param string $type The type of the parameter ('constructor' or 'method').
     * @param array $processed An array of already processed parameters.
     *
     * @return ?ReflectionClass The ReflectionClass instance if resolvable, null otherwise.
     *
     * @throws ContainerException If:
     *  - The parameter is not resolvable.
     *  - The parameter is of type 'parent' but no parent class exists.
     *  - A circular dependency is detected.
     *  - Multiple instances for the same class are detected.
     */
    private function getResolvableReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed
    ): ?ReflectionClass {
        $paramType = $parameter->getType();
        if (!$paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
            return null;
        }

        $className = $paramType->getName();
        if ($declaringClass = $parameter->getDeclaringClass()) {
            $className = match ($className) {
                'self' => $declaringClass->getName(),
                'parent' => $declaringClass->getParentClass()?->getName() ?? 'parent',
                default => $className
            };
        }
        if ($className === 'parent') {
            throw new ContainerException(
                "Parameter '{$parameter->getName()}' uses 'parent' keyword but no parent class."
            );
        }
        // (optional) environment-based interface => concrete override
        // e.g. if $className is an interface, check $this->repository->getEnvConcrete($className)
        $envConcrete = null;
        if (interface_exists($className)) {
            $envConcrete = $this->repository->getEnvConcrete($className);
        }
        $finalClassName = $envConcrete ?: $className;

        try {
            $reflection = ReflectionResource::getClassReflection($finalClassName);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to reflect class '{$finalClassName}' for parameter '{$parameter->getName()}'.",
                0,
                $e
            );
        }

        if ($type === 'constructor' &&
            $parameter->getDeclaringClass()?->getName() === $reflection->getName()
        ) {
            throw new ContainerException("Circular dependency on {$reflection->getName()}");
        }

        if ($this->alreadyExist($reflection->getName(), $processed)) {
            $shortName = $reflector->getShortName();
            $owner     = $reflector->class ?? $reflector->getName();
            throw new ContainerException(
                "Multiple instances for {$reflection->getName()} in $owner::$shortName()"
            );
        }

        return $reflection;
    }

    /**
     * Checks if an instance of the given class already exists in the given parameters.
     *
     * @param string $className The class name to look for.
     * @param array $parameters The array of parameters to search in.
     *
     * @return bool True if an instance of the given class already exists, false otherwise.
     */
    private function alreadyExist(string $className, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $className) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves a class dependency.
     *
     * If the given class has a constructor and $supplied is not null, it merges the given $supplied
     * value with the existing constructor parameters stored in the repository.
     *
     * @param ReflectionClass $class The class to resolve.
     * @param string $type The type of the parameter ('constructor' or 'method').
     * @param mixed $supplied The value to supply to the constructor, if applicable.
     *
     * @return object The resolved instance.
     * @throws ContainerException|ReflectionException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        if ($type === 'constructor' && $supplied !== null && $class->getConstructor()) {
            $existing = $this->repository->getClassResource()[$class->getName()]['constructor']['params'] ?? [];
            $merged   = array_merge($existing, (array) $supplied);
            $this->repository->addClassResource(
                $class->getName(),
                'constructor',
                ['on' => '__constructor','params' => $merged]
            );
        }
        return $this->classResolver->resolve($class)['instance'];
    }

    /**
     * Resolves an individual attribute for a given parameter.
     *
     * This method attempts to resolve a parameter's attribute value using the following strategies:
     * 1) If the attribute value exists in the function reference, resolve it by definition type.
     * 2) If the attribute value is a function, resolve it by reflecting the function and invoking it
     *    with the resolved arguments.
     *
     * @param ReflectionParameter $param The parameter for which the attribute is being resolved.
     * @param string $attributeValue The attribute value to resolve.
     * @return mixed The resolved value or a default value if resolution fails.
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveIndividualAttribute(
        ReflectionParameter $param,
        string $attributeValue
    ): mixed {
        // 1) If $attributeValue in functionReference
        $definition = $this->resolveByDefinitionType($attributeValue, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }
        // 2) If $attributeValue is a function
        if (function_exists($attributeValue)) {
            $reflectionFn = ReflectionResource::getFunctionReflection($attributeValue);
            return $attributeValue(...$this->resolve($reflectionFn, [], 'constructor'));
        }
        return $this->stdClass;
    }

    /**
     * Resolves a parameter's attribute value and returns its resolved value.
     *
     * This method checks if the parameter has an Infuse attribute and if it has arguments.
     * If it does, it resolves the attribute value using the ClassResolver and returns an array
     * containing a boolean indicating whether the value was resolved or not, and the resolved value
     * itself.
     *
     * @param ReflectionParameter $param The parameter for which the attribute is being resolved.
     * @return array An array containing a boolean indicating whether the value was resolved or not,
     *               and the resolved value itself.
     * @throws ContainerException
     * @throws ReflectionException|\Psr\Cache\InvalidArgumentException
     */
    private function resolveParameterAttribute(ReflectionParameter $param): array
    {
        $attribute = $param->getAttributes(Infuse::class);
        if (!$attribute || empty($attribute[0]->getArguments())) {
            return ['isResolved' => false];
        }
        $infuseInstance = $attribute[0]->newInstance();
        $resolved = $this->classResolver->resolveInfuse($infuseInstance);
        return [
            'isResolved' => ($this->stdClass !== $resolved),
            'resolved'   => $resolved
        ];
    }

    /**
     * Process a variadic parameter.
     *
     * Given an array of already-processed parameters, a variadic parameter, and an array of parameter
     * sort orders, return an array of all the parameters in the correct order.
     *
     * The variadic parameter is expected to be an associative array with the following keys:
     *   - value: An array of values for the variadic parameter.
     *
     * If the variadic parameter has at least one value, sort the already-processed parameters by their
     * sort order, reset their keys to be sequential integers, and then append the values of the
     * variadic parameter to the end of the array. If the variadic parameter has no values, simply merge
     * the already-processed parameters with the values of the variadic parameter.
     *
     * @param array $processed An array of already-processed parameters.
     * @param array $variadic A variadic parameter.
     * @param array $sort An array of parameter sort orders.
     *
     * @return array An array of all the parameters in the correct order.
     */
    private function processVariadic(
        array $processed,
        array $variadic,
        array $sort
    ): array {
        $variadicValue = (array) $variadic['value'];
        if (isset($variadicValue[0])) {
            uksort($processed, static fn ($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadicValue));
            return $processed;
        }
        return array_merge($processed, $variadicValue);
    }
}
