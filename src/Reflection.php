<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use Closure;
use Componenta\Reflection\Internal\CachedClassReflection;
use Componenta\Reflection\Internal\CachedFunctionReflection;
use Componenta\Reflection\Internal\CachedMethodReflection;
use Componenta\Reflection\Internal\CachedObjectReflection;
use Componenta\Reflection\Internal\NativeCallableInspection;
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
use TypeError;
use WeakMap;
use WeakReference;

final class Reflection
{
    use NativeCallableInspection;

    private const int METADATA_MISS_CACHE_LIMIT = 16;

    /** @var array<string, ReflectionClass<covariant object>> */
    private static array $classes = [];

    /** @var array<string, ReflectionFunction> */
    private static array $functions = [];

    /** @var array<string, ReflectionMethod> */
    private static array $methods = [];

    /** @var WeakMap<Closure, WeakReference<ReflectionFunction>>|null */
    private static ?WeakMap $closures = null;

    /** @var WeakMap<object, WeakReference<ReflectionObject<covariant object>>>|null */
    private static ?WeakMap $objects = null;

    /** @var WeakMap<Reflector, array<string, list<ReflectionAttribute<object>>|null>>|null */
    private static ?WeakMap $metadata = null;

    private static ?ReflectionMethod $classNameReader = null;

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @param class-string<T>|null $name
     * @return list<T>|null
     */
    public static function getMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name = null,
    ): ?array {
        /** @var list<ReflectionAttribute<T>>|null $definitions */
        $definitions = self::metadataDefinitions($reflector, $name);
        return $definitions === null ? null : self::instantiateMetadata($definitions);
    }

    /**
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
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
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @param class-string $name
     */
    public static function hasMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        string $name,
    ): bool {
        return self::metadataDefinitions($reflector, $name) !== null;
    }

    /** @return ReflectionFunctionAbstract|ReflectionClass<covariant object>|null */
    public static function reflect(mixed $value): ReflectionFunctionAbstract|ReflectionClass|ReflectionObject|null
    {
        if (is_array($value)) {
            $value = self::snapshotCallableArray($value);
        }
        if (self::isNativeCallable($value)) {
            try {
                return self::reflectCallable($value);
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

    /** @param callable $callable */
    public static function callable(callable|array $callable): ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            $callable = self::snapshotCallableArray($callable);
            if (!is_callable($callable)) {
                throw new TypeError(sprintf(
                    '%s(): Argument #1 ($callable) must be of type callable, array given.',
                    __METHOD__,
                ));
            }
        }

        return self::reflectCallable($callable);
    }

    /** @param callable $callable */
    private static function reflectCallable(mixed $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            return self::closure($callable);
        }

        if (is_string($callable)) {
            $separator = strrpos($callable, '::');
            if ($separator !== false) {
                $class = substr($callable, 0, $separator);
                $method = substr($callable, $separator + 2);

                return self::concreteMethod($class, $method, true);
            }

            $key = self::functionKey($callable);

            return self::$functions[$key] ??= new CachedFunctionReflection($callable);
        }

        if (is_array($callable)) {
            [$owner, $method] = $callable;
            if ($owner instanceof Closure && strcasecmp((string) $method, '__invoke') === 0) {
                return self::closure($owner);
            }
            if (str_contains((string) $method, '::')) {
                return self::qualifiedMethod($callable, is_string($owner));
            }
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

    /**
     * @param array<array-key, mixed> $callable
     * @return array<array-key, mixed>
     */
    private static function snapshotCallableArray(array $callable): array
    {
        if (count($callable) !== 2 || !isset($callable[0], $callable[1])) {
            return $callable;
        }

        // Own the routing slots before callable validation can load a class.
        return [$callable[0], $callable[1]];
    }

    /**
     * @template T of object
     * @param T $object
     * @return ReflectionObject<T>
     */
    public static function object(object $object): ReflectionObject
    {
        $cache = self::$objects ??= new WeakMap();
        /** @var ReflectionObject<T>|null $reflection */
        $reflection = ($cache[$object] ?? null)?->get();
        if ($reflection === null) {
            $reflection = new CachedObjectReflection($object);
            $cache[$object] = WeakReference::create($reflection);
        }
        return $reflection;
    }

    /**
     * @template T of string
     * @param T $class
     * @return ReflectionClass<new<T>>|null
     */
    public static function class(string $class): ?ReflectionClass
    {
        $key = self::classKey($class);
        if (isset(self::$classes[$key])) {
            /** @var ReflectionClass<new<T>> */
            return self::$classes[$key];
        }

        if (!class_exists($class)
            && !interface_exists($class, false)
            && !trait_exists($class, false)
            && !enum_exists($class, false)
        ) {
            return null;
        }

        /** @var class-string<new<T>> $class */
        $reflection = new CachedClassReflection($class);
        /** @var ReflectionClass<new<T>> */
        return self::$classes[$key] ??= $reflection;
    }

    /**
     * @template T of object
     * @param ReflectionClass<covariant object> $reflector
     * @param class-string<T>|null $name
     * @return array<string, list<T>>
     */
    public static function getDeepMetadata(ReflectionClass $reflector, ?string $name = null): array
    {
        // Attribute constructors can change a caller-owned reflector.
        /** @var list<array{string, list<ReflectionAttribute<T>>}> $definitions */
        $definitions = [];
        [$className, $reflector, $classDefinitions, $ownerName] = self::deepClassDefinitions($reflector, $name);
        if ($classDefinitions !== null) {
            $definitions[] = [$className, $classDefinitions];
        }

        foreach ($ownerName === null ? $reflector->getMethods() : self::readDeepMembers($reflector, $ownerName, 'getMethods') as $method) {
            self::appendMetadataDefinitions($definitions, $className . '::' . $method->getName() . '()', $method, $name);
        }

        foreach ($ownerName === null ? $reflector->getProperties() : self::readDeepMembers($reflector, $ownerName, 'getProperties') as $property) {
            self::appendMetadataDefinitions($definitions, $className . '::$' . $property->getName(), $property, $name);

            foreach ($property->getHooks() as $hook) {
                self::appendMetadataDefinitions($definitions, $className . '::' . $hook->getName() . '()', $hook, $name);
            }
        }

        foreach ($ownerName === null ? $reflector->getReflectionConstants() : self::readDeepMembers($reflector, $ownerName, 'getReflectionConstants') as $constant) {
            self::appendMetadataDefinitions($definitions, $className . '::' . $constant->getName(), $constant, $name);
        }

        $result = [];
        foreach ($definitions as [$path, $attributes]) {
            $result[$path] = self::instantiateMetadata($attributes);
        }

        return $result;
    }

    /**
     * @template T of object
     * @param ReflectionClass<covariant object> $reflector
     * @param class-string<T> $name
     * @return T|null
     */
    public static function getFirstDeepMetadata(ReflectionClass $reflector, string $name): ?object
    {
        [, $reflector, $classDefinitions, $ownerName] = self::deepClassDefinitions($reflector, $name);
        if ($classDefinitions !== null) {
            return $classDefinitions[0]->newInstance();
        }

        foreach ($ownerName === null ? $reflector->getMethods() : self::readDeepMembers($reflector, $ownerName, 'getMethods') as $method) {
            $attribute = self::getFirstMetadata($method, $name);
            if ($attribute !== null) {
                return $attribute;
            }
        }

        foreach ($ownerName === null ? $reflector->getProperties() : self::readDeepMembers($reflector, $ownerName, 'getProperties') as $property) {
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

        foreach ($ownerName === null ? $reflector->getReflectionConstants() : self::readDeepMembers($reflector, $ownerName, 'getReflectionConstants') as $constant) {
            $attribute = self::getFirstMetadata($constant, $name);
            if ($attribute !== null) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<covariant object> $reflector
     * @param class-string $name
     */
    public static function hasDeepMetadata(ReflectionClass $reflector, string $name): bool
    {
        [, $reflector, $classDefinitions, $ownerName] = self::deepClassDefinitions($reflector, $name);
        if ($classDefinitions !== null) {
            return true;
        }

        foreach ($ownerName === null ? $reflector->getMethods() : self::readDeepMembers($reflector, $ownerName, 'getMethods') as $method) {
            if (self::hasMetadata($method, $name)) {
                return true;
            }
        }

        foreach ($ownerName === null ? $reflector->getProperties() : self::readDeepMembers($reflector, $ownerName, 'getProperties') as $property) {
            if (self::hasMetadata($property, $name)) {
                return true;
            }

            foreach ($property->getHooks() as $hook) {
                if (self::hasMetadata($hook, $name)) {
                    return true;
                }
            }
        }

        foreach ($ownerName === null ? $reflector->getReflectionConstants() : self::readDeepMembers($reflector, $ownerName, 'getReflectionConstants') as $constant) {
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
        self::$classNameReader = null;
    }

    /**
     * @template T of object
     * @param ReflectionClass<covariant object> $reflector
     * @param class-string<T>|null $name
     * @return array{class-string, ReflectionClass<covariant object>, list<ReflectionAttribute<T>>|null, class-string|null}
     */
    private static function deepClassDefinitions(ReflectionClass $reflector, ?string $name): array
    {
        $ownerName = match ($reflector::class) {
            ReflectionClass::class, ReflectionObject::class, CachedClassReflection::class, CachedObjectReflection::class => null,
            default => self::nativeClassName($reflector),
        };
        $className = $reflector->getName();
        // Read the path offered by the caller and keep the native declaration selected at entry.
        if ($ownerName !== null && self::nativeClassName($reflector) !== $ownerName) {
            $reflector = new ReflectionClass($ownerName);
            $ownerName = null;
        }

        $definitions = self::metadataDefinitions($reflector, $name);
        // Custom attribute readers may retarget the borrowed reflector during a read.
        if ($ownerName !== null && self::nativeClassName($reflector) !== $ownerName) {
            $reflector = new ReflectionClass($ownerName);
            $ownerName = null;
            $definitions = self::metadataDefinitions($reflector, $name);
        }
        if ($reflector::class === ReflectionObject::class) {
            // Reading properties can initialize the reflected object.
            $ownerName = $className;
        }

        /** @var list<ReflectionAttribute<T>>|null $definitions */
        return [$className, $reflector, $definitions, $ownerName];
    }

    /**
     * @param ReflectionClass<covariant object> $reflector
     * @return class-string
     */
    private static function nativeClassName(ReflectionClass $reflector): string
    {
        $getName = self::$classNameReader ??= new ReflectionMethod(ReflectionClass::class, 'getName');

        /** @var class-string */
        return $getName->invoke($reflector);
    }

    /**
     * @param ReflectionClass<covariant object> $reflector
     * @param class-string $ownerName
     * @param 'getMethods'|'getProperties'|'getReflectionConstants' $query
     * @return ($query is 'getMethods' ? list<ReflectionMethod> : ($query is 'getProperties' ? list<ReflectionProperty> : list<ReflectionClassConstant>))
     */
    private static function readDeepMembers(ReflectionClass $reflector, string $ownerName, string $query): array
    {
        if (self::nativeClassName($reflector) === $ownerName) {
            $members = $reflector->$query();
            if (self::nativeClassName($reflector) === $ownerName) {
                return $members;
            }
        }

        return new ReflectionClass($ownerName)->$query();
    }

    /**
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
     * @return list<ReflectionAttribute<object>>|null
     */
    private static function metadataDefinitions(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name,
    ): ?array {
        if (!$reflector instanceof CachedClassReflection
            && !$reflector instanceof CachedFunctionReflection
            && !$reflector instanceof CachedMethodReflection
            && !$reflector instanceof CachedObjectReflection
        ) {
            $definitions = self::attributeDefinitions($reflector, $name);
            return $definitions === [] ? null : $definitions;
        }

        $cache = self::$metadata ??= new WeakMap();
        $key = $name === null ? 'all' : 'name:' . strtolower($name);
        $byName = $cache[$reflector] ?? [];

        if (array_key_exists($key, $byName)) {
            return $byName[$key];
        }

        $definitions = self::attributeDefinitions($reflector, $name);
        if ($definitions === [] && $name !== null && count($byName) >= self::METADATA_MISS_CACHE_LIMIT) {
            // Only observed definitions and a bounded set of misses belong in this cache.
            return null;
        }
        $byName[$key] = $definitions === [] ? null : $definitions;
        $cache[$reflector] = $byName;

        return $byName[$key];
    }

    /**
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionConstant|ReflectionProperty $reflector
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
     * @param list<array{string, list<ReflectionAttribute<T>>}> $result
     * @param-out list<array{string, list<ReflectionAttribute<T>>}> $result
     * @param ReflectionFunctionAbstract|ReflectionClass<covariant object>|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector
     * @param class-string<T>|null $name
     */
    private static function appendMetadataDefinitions(
        array &$result,
        string $path,
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionClassConstant|ReflectionProperty $reflector,
        ?string $name,
    ): void {
        /** @var list<ReflectionAttribute<T>>|null $definitions */
        $definitions = self::metadataDefinitions($reflector, $name);
        if ($definitions !== null) {
            $result[] = [$path, $definitions];
        }
    }

    /**
     * @template T of object
     * @param list<ReflectionAttribute<T>> $definitions
     * @return list<T>
     */
    private static function instantiateMetadata(array $definitions): array
    {
        $instances = [];
        foreach ($definitions as $definition) {
            $instances[] = $definition->newInstance();
        }

        return $instances;
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

    private static function qualifiedMethod(callable $callable, bool $staticOwner): ReflectionFunction
    {
        // Native capture preserves which ancestor and called scope PHP selected.
        $closure = Closure::fromCallable($callable);
        if (is_array($callable) && $closure === $callable[0]) {
            return self::closure($closure);
        }

        $reflection = new CachedFunctionReflection($closure);
        $scope = $reflection->getClosureScopeClass();
        if ($scope === null) {
            throw new \InvalidArgumentException('Qualified callable has no concrete method to reflect.');
        }
        self::concreteMethod($scope->getName(), $reflection->getName(), $staticOwner);
        return $reflection;
    }

    private static function closure(Closure $closure): ReflectionFunction
    {
        $cache = self::$closures ??= new WeakMap();
        $reflection = ($cache[$closure] ?? null)?->get();
        if ($reflection === null) {
            $reflection = new CachedFunctionReflection($closure);
            if (self::isMagicCapture($reflection)) {
                throw new \InvalidArgumentException(sprintf(
                    'Callable %s() is resolved through magic dispatch and has no concrete method to reflect.',
                    $reflection->getName(),
                ));
            }
            $cache[$closure] = WeakReference::create($reflection);
        }
        return $reflection;
    }

    private static function isMagicCapture(ReflectionFunction $reflection): bool
    {
        if (!$reflection->isInternal() || ($scope = $reflection->getClosureScopeClass()) === null) {
            return false;
        }

        $name = $reflection->getName();
        if (!$scope->hasMethod($name)) {
            return true;
        }

        // The called class may declare a different method with the trampoline's name.
        $method = $scope->getMethod($name);
        return !$method->isInternal() || $method->getDeclaringClass()->getName() !== $scope->getName();
    }

    private static function method(string $class, string $method): ReflectionMethod
    {
        $key = self::classKey($class) . '::' . strtolower($method);

        return self::$methods[$key] ??= new CachedMethodReflection($class, $method);
    }

    private static function classKey(string $class): string
    {
        return strtolower(str_starts_with($class, '\\') ? substr($class, 1) : $class);
    }

    private static function functionKey(string $function): string
    {
        return strtolower(ltrim($function, '\\'));
    }
}
