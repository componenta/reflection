<?php

namespace Componenta\Reflection;

use Closure;
use ReflectionClass;
use ReflectionConstant;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use WeakMap;

/**
 * Class Reflection
 *
 * This static helper class provides utility methods for retrieving reflection information and
 * metadata (attributes) from various reflection objects such as functions, classes, parameters,
 * constants, and properties. It also implements caching of created reflectors to improve performance.
 */
final class Reflection
{
    /**
     * @var array<string, Reflector> Cache mapping of unique identifiers to Reflector instances.
     */
    private static array $reflectors = [];

    /**
     * Per-reflector attribute-instance cache shared by {@see getMetadata()},
     * {@see getFirstMetadata()} and {@see hasMetadata()}.
     *
     * {@see WeakMap} keyed by the reflector object: entries vanish automatically
     * when the reflector is garbage-collected, which makes the cache safe for
     * short-lived reflectors created outside {@see self::class()} - a plain
     * `spl_object_id`-keyed array would poison on ID reuse.
     *
     * Inner map: attribute FQN (empty string for "all attributes") -> array of
     * instantiated attribute instances, or `null` when the reflector has none
     * matching.
     *
     * Instance reuse is safe because attributes in this codebase are readonly
     * value-objects. Call {@see clearReflectors()} to drop the cache wholesale.
     *
     * @var WeakMap<Reflector, array<string, array<object>|null>>|null
     */
    private static ?WeakMap $metadataCache = null;

    /**
     * Adds a custom reflector to the static cache.
     *
     * @param string   $id        The unique identifier for caching the reflector.
     * @param Reflector $reflector The reflector instance to cache.
     */
    public static function addReflector(string $id, Reflector $reflector): void
    {
        self::$reflectors[$id] = $reflector;
    }

    /**
     * Retrieves all metadata attributes for the provided reflection object.
     *
     * If a name is supplied, only attributes matching the given class name are returned.
     * Returns an array of Attribute instances, or null if no attributes are found.
     *
     * Results are memoised per (reflector, name) pair; consecutive calls return
     * the same array of instances. See {@see self::$metadataCache}.
     *
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector The reflection object to inspect.
     * @param class-string<T>|null $name Optional Attribute class name to filter by.
     * @return array<T>|null Returns array of Attribute instances, or null if no attributes found.
     */
    public static function getMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector,
        ?string $name = null,
    ): ?array {
        $cache = self::$metadataCache ??= new WeakMap();
        $key   = $name ?? '';
        $byName = $cache[$reflector] ?? [];

        if (array_key_exists($key, $byName)) {
            return $byName[$key];
        }

        $attributes = $reflector->getAttributes($name) ?? [];
        $result = $attributes === []
            ? null
            : array_map(
                static fn(\ReflectionAttribute $attribute) => $attribute->newInstance(),
                $attributes,
            );

        $byName[$key] = $result;
        $cache[$reflector] = $byName;

        return $result;
    }

    /**
     * Retrieves the first metadata Attribute instance for the provided reflection object that matches the given Attribute name.
     *
     * Backed by the same cache as {@see getMetadata()}.
     *
     * @template T of object
     * @param ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector The reflection object to inspect.
     * @param class-string<T> $name The Attribute class name to look for.
     * @return T|null Returns the Attribute instance if found, or null if not present.
     */
    public static function getFirstMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector,
        string $name,
    ): ?object {
        $attributes = self::getMetadata($reflector, $name);

        return $attributes === null ? null : $attributes[0];
    }

    /**
     * Checks whether the provided reflection object has any metadata attributes matching the specified Attribute name.
     *
     * Backed by the same cache as {@see getMetadata()}.
     *
     * @param ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector The reflection object to check.
     * @param string $name The Attribute class name to look for.
     * @return bool Returns true if at least one matching Attribute is found; otherwise, false.
     */
    public static function hasMetadata(
        ReflectionFunctionAbstract|ReflectionClass|ReflectionParameter|ReflectionConstant|ReflectionProperty $reflector,
        string $name,
    ): bool {
        return self::getMetadata($reflector, $name) !== null;
    }

    /**
     * Reflects a given variable and returns an appropriate reflection object.
     *
     * Supported types:
     * - Callables: Returns a reflection of the callable.
     * - Objects: Returns a ReflectionObject for the instance.
     * - Strings: Treated as a class name; returns a ReflectionClass if the class exists.
     *
     * @param mixed $var The variable to reflect.
     * @return null|ReflectionFunctionAbstract|ReflectionClass|ReflectionObject Returns a reflection object if supported, or null.
     */
    public static function reflect(mixed $var): null|ReflectionFunctionAbstract|ReflectionClass|ReflectionObject
    {
        return match (true) {
            is_callable($var) => self::callable($var),
            is_object($var) => self::object($var),
            is_string($var) => self::class($var),
            default => null
        };
    }

    /**
     * Returns a Reflection instance for the given callable.
     *
     * Depending on the type of callable:
     * - For closures, uses spl_object_hash() for caching.
     * - For string callables with "::" (e.g., "Class::method"), returns a ReflectionMethod.
     * - For simple function names, returns a ReflectionFunction.
     * - For array-style callables, returns a ReflectionMethod.
     *
     * @param callable $callable The callable to reflect.
     * @return ReflectionFunctionAbstract Returns the reflection instance corresponding to the callable.
     */
    public static function callable(callable $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            if (isset(self::$reflectors[$cacheKey = spl_object_hash($callable)])) {
                return self::$reflectors[$cacheKey];
            }

            return self::$reflectors[$cacheKey] = new ReflectionFunction($callable);
        }

        if (is_string($callable)) {
            if (isset(self::$reflectors[$callable])) return self::$reflectors[$callable];
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                return self::$reflectors[$callable] = new ReflectionMethod($class, $method);
            } else return self::$reflectors[$callable] = new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            list ($objectOrClass, $method) = $callable;
            if (is_object($objectOrClass)) $objectOrClass = $objectOrClass::class;
            return self::$reflectors["$objectOrClass::$method"] = new ReflectionMethod($objectOrClass, $method);
        }

        $cacheKey = spl_object_hash($callable);
        if (isset(self::$reflectors[$cacheKey])) return self::$reflectors[$cacheKey];
        return self::$reflectors[$callable::class] = new ReflectionMethod($callable, '__invoke');
    }

    /**
     * Returns a ReflectionObject for the given object instance.
     *
     * The created ReflectionObject is cached based on the object’s spl_object_hash.
     *
     * @param object $object The object to reflect.
     * @return ReflectionObject Returns the ReflectionObject representing the given object.
     */
    public static function object(object $object): ReflectionObject
    {
        return self::$reflectors[$id = spl_object_hash($object)] ?? self::$reflectors[$id] = new ReflectionObject($object);
    }

    /**
     * Returns a ReflectionClass for the given class name.
     *
     * If the class exists, its reflection is cached and returned. If the class does not exist, null is returned.
     *
     * @param string $class The class name to reflect.
     * @return null|ReflectionClass Returns the ReflectionClass instance if the class exists; otherwise, null.
     */
    public static function class(string $class): ?ReflectionClass
    {
        if (isset(self::$reflectors[$class])) return self::$reflectors[$class];

        try {
            return self::$reflectors[$class] = new ReflectionClass($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retrieves all metadata attributes for the provided reflection class including its methods, properties, and constants.
     *
     * @template T of object
     * @param ReflectionClass $reflector The reflection class to inspect.
     * @param class-string<T>|null $name Optional Attribute class name to filter by.
     * @return array<string, array<T>> Returns array where keys are paths and values are arrays of Attribute instances.
     *                                  Path formats: ClassName, ClassName::methodName, ClassName::$propertyName, ClassName::CONSTANT_NAME
     */
    public static function getDeepMetadata(
        ReflectionClass $reflector,
        ?string $name = null
    ): array {
        $attributes = [];
        $className = $reflector->getName();

        // Get attributes from the class itself
        $classAttributes = $reflector->getAttributes($name);
        if (!empty($classAttributes)) {
            $attributes[$className] = [];
            foreach ($classAttributes as $attribute) {
                $attributes[$className][] = $attribute->newInstance();
            }
        }

        // Search in methods
        foreach ($reflector->getMethods() as $method) {
            $methodAttributes = $method->getAttributes($name);
            if (!empty($methodAttributes)) {
                $path = $className . '::' . $method->getName();
                $attributes[$path] = [];
                foreach ($methodAttributes as $attribute) {
                    $attributes[$path][] = $attribute->newInstance();
                }
            }
        }

        // Search in properties
        foreach ($reflector->getProperties() as $property) {
            $propertyAttributes = $property->getAttributes($name);
            if (!empty($propertyAttributes)) {
                $path = $className . '::$' . $property->getName();
                $attributes[$path] = [];
                foreach ($propertyAttributes as $attribute) {
                    $attributes[$path][] = $attribute->newInstance();
                }
            }
        }

        // Search in class constants
        foreach ($reflector->getReflectionConstants() as $constant) {
            $constantAttributes = $constant->getAttributes($name);
            if (!empty($constantAttributes)) {
                $path = $className . '::' . $constant->getName();
                $attributes[$path] = [];
                foreach ($constantAttributes as $attribute) {
                    $attributes[$path][] = $attribute->newInstance();
                }
            }
        }

        return $attributes;
    }

    /**
     * Retrieves the first metadata Attribute instance found in the class or its members.
     *
     * @template T of object
     * @param ReflectionClass $reflector The reflection class to inspect.
     * @param class-string<T> $name The Attribute class name to look for.
     * @return object<T>|null Returns the first Attribute instance if found, or null if not present.
     */
    public static function getFirstDeepMetadata(
        ReflectionClass $reflector,
        string $name
    ): ?object {
        $attributes = self::getDeepMetadata($reflector, $name);

        // Get first attribute from first path
        $firstPath = array_key_first($attributes);
        if ($firstPath !== null && !empty($attributes[$firstPath])) {
            return $attributes[$firstPath][0];
        }

        return null;
    }

    /**
     * Checks whether the class or its members have any metadata attributes matching the specified name.
     *
     * @param ReflectionClass $reflector The reflection class to check.
     * @param string $name The Attribute class name to look for.
     * @return bool Returns true if at least one matching Attribute is found; otherwise, false.
     */
    public static function hasDeepMetadata(
        ReflectionClass $reflector,
        string $name
    ): bool {
        // Check class itself
        if (!empty($reflector->getAttributes($name))) {
            return true;
        }

        // Check methods
        if (array_any($reflector->getMethods(), static fn($method) => !empty($method->getAttributes($name)))) {
            return true;
        }

        // Check properties
        if (array_any($reflector->getProperties(), static fn($property) => !empty($property->getAttributes($name)))) {
            return true;
        }

        // Check constants
        return array_any($reflector->getReflectionConstants(), static fn($constant) => !empty($constant->getAttributes($name)));
    }

    public static function clearReflectors(): void
    {
        self::$reflectors = [];
        self::$metadataCache = null;
    }
}