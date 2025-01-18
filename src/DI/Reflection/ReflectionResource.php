<?php

namespace Infocyph\InterMix\DI\Reflection;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

final class ReflectionResource
{
    /**
     * Cache for reflection instances.
     */
    private static array $reflectionCache = [
        'classes' => [],
        'enums' => [],
        'functions' => [],
        'methods' => [],
    ];

    /**
     * Returns the signature of a given reflection object.
     *
     * @param  ReflectionClass|ReflectionMethod|ReflectionFunction  $reflection  The reflection object.
     * @return string The signature of the reflection object.
     */
    public static function getSignature(
        ReflectionClass|ReflectionMethod|ReflectionFunction $reflection
    ): string {
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();

        return $file && $line ? base64_encode("$file:$line") : '';
    }

    /**
     * Returns a ReflectionClass instance for the given class or object.
     *
     * @param  string|object  $class  The class name or object.
     * @return ReflectionClass A ReflectionClass instance.
     *
     * @throws ReflectionException
     */
    public static function getForClass(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;

        return self::$reflectionCache['classes'][$className] ??= new ReflectionClass($class);
    }

    /**
     * Returns a ReflectionEnum instance for the given enum name.
     *
     * @param  string  $name  The name of the enum.
     * @return ReflectionEnum A ReflectionEnum instance.
     *
     * @throws ReflectionException
     */
    public static function getForEnum(string $name): ReflectionEnum
    {
        return self::$reflectionCache['enums'][$name] ??= new ReflectionEnum($name);
    }

    /**
     * Returns a ReflectionFunction instance for the given closure or function name.
     *
     * @param  string|Closure  $closure  The closure or function name.
     * @return ReflectionFunction A ReflectionFunction instance.
     *
     * @throws ReflectionException
     */
    public static function getForFunction(string|Closure $closure): ReflectionFunction
    {
        $key = is_string($closure) ? $closure : spl_object_hash($closure);

        return self::$reflectionCache['functions'][$key] ??= new ReflectionFunction($closure);
    }

    /**
     * Resolves the reflection of a callable (function, method, or closure).
     *
     * @param  callable|array|string  $callable  The callable to reflect on.
     * @return ReflectionMethod|ReflectionFunction The reflection instance.
     *
     * @throws InvalidArgumentException If the callable is invalid.
     * @throws ReflectionException
     */
    public static function resolveCallable(callable|array|string $callable): ReflectionMethod|ReflectionFunction
    {
        if ($callable instanceof Closure || (is_string($callable) && function_exists($callable))) {
            return self::getForFunction($callable);
        }

        if (is_array($callable) && count($callable) === 2) {
            [$class, $method] = $callable;

            if (! method_exists($class, $method)) {
                throw new InvalidArgumentException("Method '$method' does not exist in class '$class'.");
            }

            return self::$reflectionCache['methods']["$class::$method"] ??= new ReflectionMethod($class, $method);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new ReflectionMethod($callable, '__invoke');
        }

        throw new InvalidArgumentException('Invalid callable provided.');
    }

    /**
     * Retrieves all methods of a class based on visibility filters.
     *
     * @param  string|object  $class  The class or object to inspect.
     * @param  int  $filter  Reflection method visibility filter.
     * @return ReflectionMethod[] An array of ReflectionMethod instances.
     *
     * @throws ReflectionException
     */
    public static function getClassMethods(string|object $class, int $filter = ReflectionMethod::IS_PUBLIC): array
    {
        return self::getForClass($class)->getMethods($filter);
    }

    /**
     * Retrieves metadata for the file associated with a reflection object.
     *
     * @param  ReflectionClass|ReflectionMethod|ReflectionFunction  $reflection  The reflection object.
     * @return array An array containing file metadata (size, modified_at, path).
     */
    public static function getFileMetadata(ReflectionClass|ReflectionMethod|ReflectionFunction $reflection): array
    {
        $file = $reflection->getFileName();

        return [
            'size' => $file ? filesize($file) : null,
            'modified_at' => $file ? filemtime($file) : null,
            'path' => $file ? realpath($file) : null,
        ];
    }

    /**
     * Scans a file and retrieves all classes defined within it.
     *
     * @param  string  $filePath  The path to the file to scan.
     * @return string[] An array of class names.
     *
     * @throws InvalidArgumentException If the file does not exist.
     */
    public static function getClassesInFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("File '$filePath' does not exist.");
        }

        $tokens = token_get_all(file_get_contents($filePath));
        $classes = [];
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                [$type, $value] = $token;

                if ($type === T_NAMESPACE) {
                    $namespace = '';
                    while (($next = $tokens[++$index]) && is_array($next) && in_array($next[0], [T_STRING, T_NS_SEPARATOR])) {
                        $namespace .= $next[1];
                    }
                }

                if ($type === T_CLASS) {
                    $className = $tokens[$index + 2][1] ?? null;
                    if ($className) {
                        $classes[] = $namespace ? "$namespace\\$className" : $className;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Dumps detailed information about a reflection object.
     *
     * @param  ReflectionClass|ReflectionMethod|ReflectionFunction  $reflection  The reflection object.
     * @return array Detailed information about the reflection object.
     */
    public static function debugReflection(
        ReflectionClass|ReflectionMethod|ReflectionFunction $reflection
    ): array {
        return [
            'name' => $reflection->getName(),
            'file' => $reflection->getFileName(),
            'start_line' => $reflection->getStartLine(),
            'end_line' => $reflection->getEndLine(),
            'doc_comment' => $reflection->getDocComment(),
        ];
    }

    /**
     * Retrieves the namespace of a class.
     *
     * @param  string|object  $class  The class or object to inspect.
     * @return string|null The namespace of the class, or null if none exists.
     *
     * @throws ReflectionException
     */
    public static function getNamespace(string|object $class): ?string
    {
        return self::getForClass($class)->getNamespaceName();
    }

    /**
     * Retrieves the class hierarchy (parent classes and interfaces).
     *
     * @param  string|object  $class  The class or object to inspect.
     * @return array An array containing parent classes and interfaces.
     *
     * @throws ReflectionException
     */
    public static function getClassHierarchy(string|object $class): array
    {
        $reflection = self::getForClass($class);
        $parentClasses = [];
        $current = $reflection;

        while ($current = $current->getParentClass()) {
            $parentClasses[] = $current->getName();
        }

        return [
            'parent_classes' => $parentClasses,
            'interfaces' => $reflection->getInterfaceNames(),
        ];
    }

    /**
     * Retrieves the constructor dependencies for a class.
     *
     * @param  string|object  $class  The class or object to inspect.
     * @return array An array of parameter details.
     *
     * @throws ReflectionException
     */
    public static function getConstructorDependencies(string|object $class): array
    {
        $constructor = self::getForClass($class)->getConstructor();

        if (! $constructor) {
            return [];
        }

        return array_map(
            fn (ReflectionParameter $param) => self::getParameterDetails($param),
            $constructor->getParameters()
        );
    }

    /**
     * Retrieves the parameters of a given method.
     *
     * @param  string|object  $class  The class or object containing the method.
     * @param  string  $method  The method name.
     * @return array An array of parameter details.
     *
     * @throws ReflectionException
     */
    public static function getMethodParameters(string|object $class, string $method): array
    {
        $reflection = new ReflectionMethod($class, $method);

        return array_map(
            fn (ReflectionParameter $param) => self::getParameterDetails($param),
            $reflection->getParameters()
        );
    }

    /**
     * Retrieves the parameters of a given function or closure.
     *
     * @param  string|Closure  $function  The function name or closure.
     * @return array An array of parameter details.
     *
     * @throws ReflectionException
     */
    public static function getFunctionParameters(string|Closure $function): array
    {
        $reflection = new ReflectionFunction($function);

        return array_map(
            fn (ReflectionParameter $param) => self::getParameterDetails($param),
            $reflection->getParameters()
        );
    }

    /**
     * Extracts detailed information about a parameter.
     *
     * @param  ReflectionParameter  $param  The parameter to inspect.
     * @return array Detailed parameter information.
     */
    private static function getParameterDetails(ReflectionParameter $param): array
    {
        return [
            'name' => $param->getName(),
            'type' => $param->getType()?->getName(),
            'is_optional' => $param->isOptional(),
            'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            'allows_null' => $param->allowsNull(),
            'is_variadic' => $param->isVariadic(),
        ];
    }
}
