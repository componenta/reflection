<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use ReflectionIntersectionType;
use ReflectionNamedType;
use \ReflectionType as NativeReflectionType;
use InvalidArgumentException;
use Componenta\Arrayable\Arrayable;
use ReflectionUnionType;
use Stringable;

/**
 * The ReflectionType class provides utility methods for type matching, coercion, and string conversion.
 */
final class ReflectionType
{
    /**
     * Checks if a variable matches a given type.
     *
     * @param NativeReflectionType|null $type The reflected type that the variable should match.
     * @param mixed $var The variable to validate against the type.
     * @param bool $strict If true, strict comparisons are used for built-in types.
     * @return bool Returns true if the variable matches the type; otherwise, false.
     */
    public static function match(?NativeReflectionType $type, mixed $var, bool $strict = false): bool
    {
        return match (true) {
            $type === null => true,
            $type instanceof ReflectionNamedType => self::matchNamedType($type, $var, $strict),
            $type instanceof ReflectionUnionType => self::matchUnionType($type, $var, $strict),
            $type instanceof ReflectionIntersectionType => self::matchIntersectionType($type, $var, $strict),
            default => throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class)),
        };
    }

    /**
     * Checks if a value can be coerced to the given type.
     *
     * @param NativeReflectionType $type The reflected type.
     * @param mixed $value The value to check.
     * @return bool
     */
    public static function canCoerce(NativeReflectionType $type, mixed $value): bool
    {
        if ($type->allowsNull() && $value === null) {
            return true;
        }

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return self::match($type, $value);
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int', 'float' => is_numeric($value) || is_bool($value),
            'string' => is_scalar($value) || $value instanceof Stringable || $value === null,
            'bool' => is_scalar($value) || $value === null,
            'array' => is_array($value) || is_iterable($value),
            'object' => is_object($value) || is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            default => self::match($type, $value),
        };
    }

    /**
     * Coerces a value to the given type.
     *
     * @param NativeReflectionType $type The reflected type.
     * @param mixed $value The value to coerce.
     * @return mixed The coerced value.
     */
    public static function coerce(NativeReflectionType $type, mixed $value): mixed
    {
        if ($type->allowsNull() && $value === null) {
            return null;
        }

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            'array' => self::coerceToArray($value),
            'object' => (object) $value,
            default => $value,
        };
    }

    /**
     * Returns the string representation of a type.
     *
     * @param NativeReflectionType $type The reflected type.
     * @return string
     */
    public static function toString(NativeReflectionType $type): string
    {
        return match (true) {
            $type instanceof ReflectionNamedType => self::namedTypeToString($type),
            $type instanceof ReflectionUnionType => self::unionTypeToString($type),
            $type instanceof ReflectionIntersectionType => self::intersectionTypeToString($type),
            default => (string) $type,
        };
    }

    /**
     * Gets all type names for compound types.
     *
     * @param NativeReflectionType $type The reflected type.
     * @return array<string>
     */
    public static function getTypeNames(NativeReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return array_map(
                static fn(ReflectionNamedType $t) => $t->getName(),
                $type->getTypes()
            );
        }

        return [];
    }

    /**
     * Checks if the type contains a specific type name.
     *
     * @param ?NativeReflectionType $type The reflected type.
     * @param string $typeName Type name to check for.
     * @return bool
     */
    public static function contains(?NativeReflectionType $type, string $typeName): bool
    {
        if ($type === null) return false;
        return in_array($typeName, self::getTypeNames($type), true);
    }

    /**
     * Matches a value against a named type.
     */
    private static function matchNamedType(ReflectionNamedType $type, mixed $var, bool $strict): bool
    {
        if ($type->allowsNull() && $var === null) {
            return true;
        }

        if ($type->isBuiltin()) {
            return self::matchBuiltinType($type->getName(), $var, $strict);
        }

        return $var instanceof ($type->getName());
    }

    /**
     * Matches a value against a built-in type.
     */
    private static function matchBuiltinType(string $typeName, mixed $var, bool $strict): bool
    {
        return match ($typeName) {
            'mixed' => true,
            'null' => $var === null,
            'object' => is_object($var),
            'array' => is_array($var),
            'bool' => is_bool($var),
            'int' => $strict ? is_int($var) : is_numeric($var),
            'float' => $strict ? is_float($var) : is_numeric($var),
            'string' => $strict ? is_string($var) : (is_string($var) || $var instanceof Stringable),
            'false' => $var === false,
            'true' => $var === true,
            'callable' => is_callable($var),
            'iterable' => is_iterable($var),
            'resource' => is_resource($var),
            default => false,
        };
    }

    /**
     * Matches a value against a union type.
     */
    private static function matchUnionType(ReflectionUnionType $type, mixed $var, bool $strict): bool
    {
        if ($type->allowsNull() && $var === null) {
            return true;
        }

        return array_any($type->getTypes(), static fn($subType) => ReflectionType::match($subType, $var, $strict));
    }

    /**
     * Matches a value against an intersection type.
     */
    private static function matchIntersectionType(ReflectionIntersectionType $type, mixed $var, bool $strict): bool
    {
        if ($type->allowsNull() && $var === null) {
            return true;
        }

        return array_all($type->getTypes(), static fn($subType) => ReflectionType::match($subType, $var, $strict));
    }

    /**
     * Coerces a value to an array.
     */
    private static function coerceToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_iterable($value)) {
            return iterator_to_array($value);
        }

        if ($value instanceof Arrayable
            || (is_object($value) && method_exists($value, 'toArray'))) return $value->toArray();
        if ($value === null) return [];

        return [$value];
    }

    /**
     * Converts a named type to string.
     */
    private static function namedTypeToString(ReflectionNamedType $type): string
    {
        $name = $type->getName();

        if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
            return '?' . $name;
        }

        return $name;
    }

    /**
     * Converts a union type to string.
     */
    private static function unionTypeToString(ReflectionUnionType $type): string
    {
        return implode('|', array_map(self::toString(...), $type->getTypes()));
    }

    /**
     * Converts an intersection type to string.
     */
    private static function intersectionTypeToString(ReflectionIntersectionType $type): string
    {
        return implode('&', array_map(self::toString(...), $type->getTypes()));
    }
}
