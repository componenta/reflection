<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionConstant;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use WeakMap;

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

    /** @var WeakMap<Reflector, array<string, list<ReflectionAttribute<object>>|null>>|null */
    private static ?WeakMap $metadata = null;

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @param class-string<T>|null $name
     * @return list<T>|null
     */
    public static function getMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name = null,
    ): ?array {
        $definitions = self::metadataDefinitions($reflector, $name);
        if ($definitions === null) {
            return null;
        }

        $instances = [];
        foreach ($definitions as $definition) {
            /** @var T $instance */
            $instance = $definition->newInstance();
            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @param class-string<T> $name
     * @return T|null
     */
    public static function getFirstMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        string $name,
    ): ?object {
        $definitions = self::metadataDefinitions($reflector, $name);
        if ($definitions === null) {
            return null;
        }

        /** @var T $instance */
        $instance = $definitions[0]->newInstance();

        return $instance;
    }

    /**
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @param class-string $name
     */
    public static function hasMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        string $name,
    ): bool {
        return self::metadataDefinitions($reflector, $name) !== null;
    }

    /** @return ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionObject<object>|null */
    public static function reflect(mixed $value): ReflectionFunctionAbstract|ReflectionClass|ReflectionObject|null
    {
        if (is_callable($value)) {
            try {
                return self::callable($value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return match (true) {
            is_object($value) => self::object($value),
            is_string($value) => self::class($value),
            default => null,
        };
    }

    public static function callable(callable $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            $cache = self::$closures ??= new WeakMap();

            return $cache[$callable] ??= new ReflectionFunction($callable);
        }

        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);

                return self::concreteMethod($class, $method, true);
            }

            $key = self::functionKey($callable);

            return self::$functions[$key] ??= new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            [$owner, $method] = $callable;
            if (is_object($owner)) {
                $class = $owner::class;
            } elseif (is_string($owner)) {
                $class = $owner;
            } else {
                throw new \LogicException('Callable method owner must be an object or class name.');
            }

            return self::concreteMethod($class, (string) $method, is_string($owner));
        }

        if (!is_object($callable)) {
            throw new \LogicException('Unsupported callable representation.');
        }

        return self::method($callable::class, '__invoke');
    }

    /** @return ReflectionObject<object> */
    public static function object(object $object): ReflectionObject
    {
        $cache = self::$objects ??= new WeakMap();

        return $cache[$object] ??= new ReflectionObject($object);
    }

    /** @return ReflectionClass<object>|null */
    public static function class(string $class): ?ReflectionClass
    {
        $key = self::classKey($class);
        if (isset(self::$classes[$key])) {
            return self::$classes[$key];
        }

        if (!class_exists($class)
            && !interface_exists($class, false)
            && !trait_exists($class, false)
            && !enum_exists($class, false)
        ) {
            return null;
        }

        /** @var class-string $class */
        return self::$classes[$key] = new ReflectionClass($class);
    }

    /**
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
            self::appendMetadata($result, $className . '::' . $method->getName() . '()', $method, $name);
        }

        foreach ($reflector->getProperties() as $property) {
            self::appendMetadata($result, $className . '::$' . $property->getName(), $property, $name);

            foreach ($property->getHooks() as $hook) {
                self::appendMetadata($result, $className . '::' . $hook->getName() . '()', $hook, $name);
            }
        }

        foreach ($reflector->getReflectionConstants() as $constant) {
            self::appendMetadata($result, $className . '::' . $constant->getName(), $constant, $name);
        }

        return $result;
    }

    /**
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
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @return list<ReflectionAttribute<object>>|null
     */
    private static function metadataDefinitions(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name,
    ): ?array {
        $cache = self::$metadata ??= new WeakMap();
        $key = $name === null ? 'all' : 'name:' . strtolower($name);
        $byName = $cache[$reflector] ?? [];

        if (array_key_exists($key, $byName)) {
            return $byName[$key];
        }

        $definitions = self::attributeDefinitions($reflector, $name);
        $byName[$key] = $definitions === [] ? null : $definitions;
        $cache[$reflector] = $byName;

        return $byName[$key];
    }

    /**
     * @param ReflectionFunctionAbstract|ReflectionClass<object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @return list<ReflectionAttribute<object>>
     */
    private static function attributeDefinitions(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name,
    ): array {
        if (!$reflector instanceof ReflectionConstant) {
            /** @var list<ReflectionAttribute<object>> */
            return $reflector->getAttributes($name);
        }

        $method = self::constantAttributeMethod();
        if (!method_exists($reflector, $method)) {
            return [];
        }

        /** @var list<ReflectionAttribute<object>> $attributes */
        $attributes = new ReflectionMethod($reflector, $method)->invoke($reflector, $name);

        return $attributes;
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

    private static function constantAttributeMethod(): string
    {
        return 'getAttributes';
    }

    private static function concreteMethod(string $class, string $method, bool $staticOwner): ReflectionMethod
    {
        if (!method_exists($class, $method)) {
            throw new \InvalidArgumentException(sprintf(
                'Callable %s::%s() is resolved through magic dispatch and has no concrete method to reflect.',
                $class,
                $method,
            ));
        }

        $reflection = self::method($class, $method);
        if (!$reflection->isPublic() || ($staticOwner && !$reflection->isStatic())) {
            throw new \InvalidArgumentException(sprintf(
                'Callable %s::%s() is resolved through magic dispatch rather than the declared method.',
                $class,
                $method,
            ));
        }

        return $reflection;
    }

    private static function method(string $class, string $method): ReflectionMethod
    {
        $key = self::classKey($class) . '::' . strtolower($method);

        return self::$methods[$key] ??= new ReflectionMethod($class, $method);
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
