<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use WeakMap;

/**
 * Cached helpers around PHP's native reflection API.
 */
final class Reflection
{
    /** @var array<string, ReflectionClass<object>> */
    private static array $classes = [];

    /** @var array<string, ReflectionFunction> */
    private static array $functions = [];

    /** @var array<string, ReflectionMethod> */
    private static array $methods = [];

    /** @var WeakMap<Closure, ReflectionFunction>|null */
    private static ?WeakMap $closures = null;

    /** @var WeakMap<object, ReflectionObject<object>>|null */
    private static ?WeakMap $objects = null;

    /**
     * Cached attribute definitions. Attribute instances themselves are deliberately
     * not cached: user-defined attributes may be mutable.
     *
     * @var WeakMap<Reflector, array<string, list<ReflectionAttribute<object>>|null>>|null
     */
    private static ?WeakMap $metadata = null;

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @param class-string<T>|null $name
     * @return list<T>|null
     */
    public static function getMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        ?string $name = null,
    ): ?array {
        $attributes = self::metadataDefinitions($reflector, $name);
        if ($attributes === null) {
            return null;
        }

        $instances = [];
        foreach ($attributes as $attribute) {
            /** @var T $instance */
            $instance = $attribute->newInstance();
            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @param class-string<T> $name
     * @return T|null
     */
    public static function getFirstMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        string $name,
    ): ?object {
        $attributes = self::metadataDefinitions($reflector, $name);
        if ($attributes === null) {
            return null;
        }

        /** @var T $instance */
        $instance = $attributes[0]->newInstance();

        return $instance;
    }

    /**
     * Checks for an attribute without instantiating it.
     *
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @param class-string $name
     */
    public static function hasMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        string $name,
    ): bool {
        return self::metadataDefinitions($reflector, $name) !== null;
    }

    /**
     * Reflects a supported value, or returns null when no reflector can be produced.
     *
     * @return ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionObject<object>|null
     */
    public static function reflect(mixed $var): ReflectionFunctionAbstract|ReflectionClass|ReflectionObject|null
    {
        return match (true) {
            is_callable($var) => self::callable($var),
            is_object($var) => self::object($var),
            is_string($var) => self::class($var),
            default => null,
        };
    }

    /**
     * Returns a cached reflector for a callable.
     */
    public static function callable(callable $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            $cache = self::$closures ??= new WeakMap();
            if (isset($cache[$callable])) {
                return $cache[$callable];
            }

            return $cache[$callable] = new ReflectionFunction($callable);
        }

        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);

                return self::method($class, $method);
            }

            $key = self::functionKey($callable);
            if (isset(self::$functions[$key])) {
                return self::$functions[$key];
            }

            return self::$functions[$key] = new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            [$objectOrClass, $method] = $callable;
            if (is_object($objectOrClass)) {
                $class = $objectOrClass::class;
            } elseif (is_string($objectOrClass)) {
                $class = $objectOrClass;
            } else {
                throw new \LogicException('Callable method owner must be an object or class name.');
            }

            return self::method($class, (string) $method);
        }

        if (!is_object($callable)) {
            throw new \LogicException('Unsupported callable representation.');
        }

        return self::method($callable::class, '__invoke');
    }

    /**
     * Returns a cached reflector for an object without keeping that object alive.
     *
     * @return ReflectionObject<object>
     */
    public static function object(object $object): ReflectionObject
    {
        $cache = self::$objects ??= new WeakMap();
        if (isset($cache[$object])) {
            return $cache[$object];
        }

        return $cache[$object] = new ReflectionObject($object);
    }

    /**
     * Returns a cached reflector for a class.
     *
     * Only a missing/invalid reflected class is converted to null. Exceptions raised
     * by an autoloader propagate so the original application failure is not hidden.
     *
     * @return ReflectionClass<object>|null
     */
    public static function class(string $class): ?ReflectionClass
    {
        $key = self::classKey($class);
        if (isset(self::$classes[$key])) {
            return self::$classes[$key];
        }

        if (!class_exists($class)
            && !interface_exists($class)
            && !trait_exists($class)
            && !enum_exists($class)
        ) {
            return null;
        }

        /** @var class-string $class */
        return self::$classes[$key] = new ReflectionClass($class);
    }

    /**
     * Collects attributes from a class, methods, properties, property hooks and constants.
     *
     * Method paths end in `()` so they cannot collide with class-constant paths.
     * Property-hook paths follow native hook names, for example `Class::$name::get()`.
     *
     * @template T of object
     * @param ReflectionClass<object> $reflector
     * @param class-string<T>|null $name
     * @return array<string, list<T>>
     */
    public static function getDeepMetadata(ReflectionClass $reflector, ?string $name = null): array
    {
        $result = [];
        $className = $reflector->getName();

        self::appendMetadata($result, $className, $reflector, $name);

        foreach ($reflector->getMethods() as $method) {
            self::appendMetadata(
                $result,
                $className . '::' . $method->getName() . '()',
                $method,
                $name,
            );
        }

        foreach ($reflector->getProperties() as $property) {
            self::appendMetadata(
                $result,
                $className . '::$' . $property->getName(),
                $property,
                $name,
            );

            foreach ($property->getHooks() as $hook) {
                self::appendMetadata(
                    $result,
                    $className . '::' . $hook->getName() . '()',
                    $hook,
                    $name,
                );
            }
        }

        foreach ($reflector->getReflectionConstants() as $constant) {
            self::appendMetadata(
                $result,
                $className . '::' . $constant->getName(),
                $constant,
                $name,
            );
        }

        return $result;
    }

    /**
     * Returns the first matching deep attribute while instantiating only that attribute.
     *
     * @template T of object
     * @param ReflectionClass<object> $reflector
     * @param class-string<T> $name
     * @return T|null
     */
    public static function getFirstDeepMetadata(ReflectionClass $reflector, string $name): ?object
    {
        $attribute = self::getFirstMetadata($reflector, $name);
        if ($attribute !== null) {
            return $attribute;
        }

        foreach ($reflector->getMethods() as $method) {
            $attribute = self::getFirstMetadata($method, $name);
            if ($attribute !== null) {
                return $attribute;
            }
        }

        foreach ($reflector->getProperties() as $property) {
            $attribute = self::getFirstMetadata($property, $name);
            if ($attribute !== null) {
                return $attribute;
            }

            foreach ($property->getHooks() as $hook) {
                $attribute = self::getFirstMetadata($hook, $name);
                if ($attribute !== null) {
                    return $attribute;
                }
            }
        }

        foreach ($reflector->getReflectionConstants() as $constant) {
            $attribute = self::getFirstMetadata($constant, $name);
            if ($attribute !== null) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Checks deep metadata without instantiating attributes.
     *
     * @param ReflectionClass<object> $reflector
     * @param class-string $name
     */
    public static function hasDeepMetadata(ReflectionClass $reflector, string $name): bool
    {
        if (self::hasMetadata($reflector, $name)) {
            return true;
        }

        foreach ($reflector->getMethods() as $method) {
            if (self::hasMetadata($method, $name)) {
                return true;
            }
        }

        foreach ($reflector->getProperties() as $property) {
            if (self::hasMetadata($property, $name)) {
                return true;
            }

            foreach ($property->getHooks() as $hook) {
                if (self::hasMetadata($hook, $name)) {
                    return true;
                }
            }
        }

        foreach ($reflector->getReflectionConstants() as $constant) {
            if (self::hasMetadata($constant, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears all process-local caches.
     */
    public static function clearReflectors(): void
    {
        self::$classes = [];
        self::$functions = [];
        self::$methods = [];
        self::$closures = null;
        self::$objects = null;
        self::$metadata = null;
    }

    /**
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @return list<ReflectionAttribute<object>>|null
     */
    private static function metadataDefinitions(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        ?string $name,
    ): ?array {
        $cache = self::$metadata ??= new WeakMap();
        $key = $name ?? '';
        $byName = $cache[$reflector] ?? [];

        if (array_key_exists($key, $byName)) {
            return $byName[$key];
        }

        /** @var list<ReflectionAttribute<object>> $attributes */
        $attributes = $reflector->getAttributes($name);
        $byName[$key] = $attributes === [] ? null : $attributes;
        $cache[$reflector] = $byName;

        return $byName[$key];
    }

    /**
     * @template T of object
     * @param array<string, list<T>> $result
     * @param-out array<string, list<T>> $result
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @param class-string<T>|null $name
     */
    private static function appendMetadata(
        array &$result,
        string $path,
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        ?string $name,
    ): void {
        $metadata = self::getMetadata($reflector, $name);
        if ($metadata !== null) {
            $result[$path] = $metadata;
        }
    }

    private static function method(string $class, string $method): ReflectionMethod
    {
        $key = self::classKey($class) . '::' . strtolower($method);
        if (isset(self::$methods[$key])) {
            return self::$methods[$key];
        }

        return self::$methods[$key] = new ReflectionMethod($class, $method);
    }

    private static function classKey(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }

    private static function functionKey(string $function): string
    {
        return strtolower(ltrim($function, '\\'));
    }
}
