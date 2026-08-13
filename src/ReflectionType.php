<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use Componenta\Arrayable\Arrayable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType as NativeReflectionType;
use ReflectionUnionType;
use Stringable;

final class ReflectionType
{
    public static function match(?NativeReflectionType $type, mixed $var, bool $strict = false, ReflectionClass|string|null $scope = null): bool
    {
        return $type === null || self::matchType($type, $var, $strict, self::normalizeScope($scope));
    }

    public static function canCoerce(NativeReflectionType $type, mixed $value, ReflectionClass|string|null $scope = null): bool
    {
        return self::canCoerceType($type, $value, self::normalizeScope($scope));
    }

    public static function coerce(NativeReflectionType $type, mixed $value, ReflectionClass|string|null $scope = null): mixed
    {
        return self::coerceType($type, $value, self::normalizeScope($scope));
    }

    public static function toString(NativeReflectionType $type): string
    {
        return (string) $type;
    }

    /** @return list<string> */
    public static function getTypeNames(NativeReflectionType $type, ReflectionClass|string|null $scope = null): array
    {
        $names = [];
        self::collectTypeNames($type, self::normalizeScope($scope), $names);

        return array_values(array_unique($names));
    }

    public static function contains(?NativeReflectionType $type, string $typeName, ReflectionClass|string|null $scope = null): bool
    {
        if ($type === null) {
            return false;
        }

        foreach (self::getTypeNames($type, $scope) as $name) {
            if ($name === $typeName) {
                return true;
            }
        }

        return false;
    }

    private static function matchType(NativeReflectionType $type, mixed $var, bool $strict, ?ReflectionClass $scope): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return self::matchNamedType($type, $var, $strict, $scope);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                if (self::matchType($subType, $var, $strict, $scope)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $subType) {
                if (!self::matchType($subType, $var, $strict, $scope)) {
                    return false;
                }
            }

            return true;
        }

        throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class));
    }

    private static function matchNamedType(ReflectionNamedType $type, mixed $var, bool $strict, ?ReflectionClass $scope): bool
    {
        if ($type->allowsNull() && $var === null) {
            return true;
        }

        if ($type->isBuiltin()) {
            return self::matchBuiltinType($type->getName(), $var, $strict);
        }

        $name = self::resolveNamedTypeName($type, $scope, requireContext: true);

        return is_object($var) && $var instanceof $name;
    }

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
            'string' => $strict ? is_string($var) : is_string($var) || $var instanceof Stringable,
            'false' => $var === false,
            'true' => $var === true,
            'callable' => is_callable($var),
            'iterable' => is_iterable($var),
            'resource' => is_resource($var),
            'never', 'void' => false,
            default => false,
        };
    }

    private static function canCoerceType(NativeReflectionType $type, mixed $value, ?ReflectionClass $scope): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return self::canCoerceNamedType($type, $value, $scope);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return self::matchType($type, $value, true, $scope);
        }

        if ($type instanceof ReflectionUnionType) {
            if (self::matchType($type, $value, true, $scope)) {
                return true;
            }

            $coercible = 0;
            foreach ($type->getTypes() as $subType) {
                if (!self::canCoerceType($subType, $value, $scope)) {
                    continue;
                }

                if (++$coercible > 1) {
                    return false;
                }
            }

            return $coercible === 1;
        }

        throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class));
    }

    private static function canCoerceNamedType(ReflectionNamedType $type, mixed $value, ?ReflectionClass $scope): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }

        if (!$type->isBuiltin()) {
            return self::matchNamedType($type, $value, true, $scope);
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int', 'float' => is_int($value) || is_float($value) || is_bool($value) || is_string($value) && is_numeric($value),
            'string' => is_scalar($value) || $value instanceof Stringable,
            'bool' => is_scalar($value),
            'array' => is_array($value) || is_iterable($value) || $value instanceof Arrayable,
            'object' => is_object($value) || is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'null', 'false', 'true', 'resource' => self::matchBuiltinType($type->getName(), $value, true),
            'never', 'void' => false,
            default => self::matchBuiltinType($type->getName(), $value, true),
        };
    }

    private static function coerceType(NativeReflectionType $type, mixed $value, ?ReflectionClass $scope): mixed
    {
        if ($type instanceof ReflectionNamedType) {
            return self::coerceNamedType($type, $value, $scope);
        }

        if ($type instanceof ReflectionIntersectionType) {
            if (self::matchType($type, $value, true, $scope)) {
                return $value;
            }

            throw self::coercionException($type, $value);
        }

        if ($type instanceof ReflectionUnionType) {
            if (self::matchType($type, $value, true, $scope)) {
                return $value;
            }

            $candidate = null;
            foreach ($type->getTypes() as $subType) {
                if (!self::canCoerceType($subType, $value, $scope)) {
                    continue;
                }

                if ($candidate !== null) {
                    throw new InvalidArgumentException(sprintf(
                        'Value of type %s has an ambiguous coercion to %s.',
                        get_debug_type($value),
                        self::toString($type),
                    ));
                }

                $candidate = $subType;
            }

            if ($candidate === null) {
                throw self::coercionException($type, $value);
            }

            return self::coerceType($candidate, $value, $scope);
        }

        throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class));
    }

    private static function coerceNamedType(ReflectionNamedType $type, mixed $value, ?ReflectionClass $scope): mixed
    {
        if ($value === null && $type->allowsNull()) {
            return null;
        }

        if (!$type->isBuiltin()) {
            if (self::matchNamedType($type, $value, true, $scope)) {
                return $value;
            }

            throw self::coercionException($type, $value);
        }

        if (!self::canCoerceNamedType($type, $value, $scope)) {
            throw self::coercionException($type, $value);
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            'array' => self::coerceToArray($value),
            'object' => is_object($value) ? $value : (object) $value,
            default => $value,
        };
    }

    private static function coerceToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_iterable($value)) {
            return iterator_to_array($value);
        }

        throw new InvalidArgumentException(sprintf(
            'Value of type %s cannot be coerced to array.',
            get_debug_type($value),
        ));
    }

    /** @param list<string> $names */
    private static function collectTypeNames(NativeReflectionType $type, ?ReflectionClass $scope, array &$names): void
    {
        if ($type instanceof ReflectionNamedType) {
            $names[] = self::resolveNamedTypeName($type, $scope, false);
            return;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $subType) {
                self::collectTypeNames($subType, $scope, $names);
            }
            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class));
    }

    private static function resolveNamedTypeName(ReflectionNamedType $type, ?ReflectionClass $scope, bool $requireContext): string
    {
        $name = $type->getName();
        if ($name !== 'self' && $name !== 'parent' && $name !== 'static') {
            return $name;
        }

        if ($scope === null) {
            if ($requireContext) {
                throw new InvalidArgumentException(sprintf('Type "%s" requires declaring class context.', $name));
            }

            return $name;
        }

        if ($name === 'self' || $name === 'static') {
            return $scope->getName();
        }

        $parent = $scope->getParentClass();
        if ($parent === false) {
            throw new InvalidArgumentException(sprintf(
                'Type "parent" cannot be resolved because %s has no parent class.',
                $scope->getName(),
            ));
        }

        return $parent->getName();
    }

    private static function normalizeScope(ReflectionClass|string|null $scope): ?ReflectionClass
    {
        if ($scope === null || $scope instanceof ReflectionClass) {
            return $scope;
        }

        try {
            return new ReflectionClass($scope);
        } catch (ReflectionException $exception) {
            throw new InvalidArgumentException(
                sprintf('Invalid reflection type scope: %s.', $scope),
                previous: $exception,
            );
        }
    }

    private static function coercionException(NativeReflectionType $type, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Value of type %s cannot be coerced to %s.',
            get_debug_type($value),
            self::toString($type),
        ));
    }
}
