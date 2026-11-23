<?php

declare(strict_types=1);

namespace Componenta\Reflection;

use Componenta\Arrayable\Arrayable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType as NativeReflectionType;
use ReflectionUnionType;
use Stringable;
use TypeError;

/**
 * Utilities for inspecting, matching and performing simple native PHP type coercion.
 *
 * This class deliberately does not replace componenta/caster: it only understands
 * reflected PHP types and PHP-level conversions, not named/domain-specific casters.
 */
final class ReflectionType
{
    /**
     * Checks whether a value matches a reflected PHP type.
     *
     * Supply $scope when the reflected type may contain self, parent or static.
     *
     * @param ReflectionClass<covariant object>|class-string|null $scope
     */
    public static function match(
        ?NativeReflectionType $type,
        mixed $var,
        bool $strict = false,
        ReflectionClass|string|null $scope = null,
    ): bool {
        if ($type === null) {
            return true;
        }

        return self::matchType($type, $var, $strict, self::normalizeScope($scope));
    }

    /**
     * Checks eligibility for an unambiguous PHP-level conversion.
     * Iterable keys are validated when coerce() traverses the input.
     *
     * @param ReflectionClass<covariant object>|class-string|null $scope
     */
    public static function canCoerce(
        NativeReflectionType $type,
        mixed $value,
        ReflectionClass|string|null $scope = null,
    ): bool {
        return self::canCoerceType($type, $value, self::normalizeScope($scope));
    }

    /**
     * Coerces a value to a reflected PHP type.
     *
     * Union coercion is allowed only when the value already matches one branch or
     * exactly one branch is coercible. Ambiguous conversions are rejected.
     *
     * @param ReflectionClass<covariant object>|class-string|null $scope
     * @throws InvalidArgumentException When the conversion is impossible or ambiguous.
     */
    public static function coerce(
        NativeReflectionType $type,
        mixed $value,
        ReflectionClass|string|null $scope = null,
    ): mixed {
        return self::coerceType($type, $value, self::normalizeScope($scope));
    }

    public static function toString(NativeReflectionType $type): string
    {
        return (string) $type;
    }

    /**
     * @param ReflectionClass<covariant object>|class-string|null $scope
     * @return list<string>
     */
    public static function getTypeNames(
        ?NativeReflectionType $type,
        ReflectionClass|string|null $scope = null,
    ): array {
        if ($type === null) {
            return [];
        }

        $names = [];
        self::collectTypeNames($type, self::normalizeScope($scope), $names);

        return array_values(array_unique($names));
    }

    /** @param ReflectionClass<covariant object>|class-string|null $scope */
    public static function contains(
        ?NativeReflectionType $type,
        string $typeName,
        ReflectionClass|string|null $scope = null,
    ): bool {
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

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function matchType(
        NativeReflectionType $type,
        mixed $var,
        bool $strict,
        ?ReflectionClass $scope,
    ): bool {
        if ($type instanceof ReflectionNamedType) {
            if ($type->allowsNull() && $var === null) {
                return true;
            }

            if ($type->isBuiltin()) {
                return self::matchBuiltinType($type->getName(), $var, $strict);
            }

            $name = self::resolveNamedTypeName($type, $scope, requireContext: true);

            return is_object($var) && $var instanceof $name;
        }

        if ($type instanceof ReflectionUnionType) {
            $hasCallable = false;
            foreach ($type->getTypes() as $subType) {
                // A matching ordinary member needs no callable lookup or autoload.
                if ($subType instanceof ReflectionNamedType && $subType->getName() === 'callable') {
                    $hasCallable = true;
                    continue;
                }
                if (self::matchType($subType, $var, $strict, $scope)) {
                    return true;
                }
            }

            return $hasCallable && self::matchBuiltinType('callable', $var, $strict);
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

    private static function matchBuiltinType(string $typeName, mixed $var, bool $strict): bool
    {
        return match ($typeName) {
            'mixed' => true,
            'null' => $var === null,
            'object' => is_object($var),
            'array' => is_array($var),
            'bool' => is_bool($var),
            'int' => $strict ? is_int($var) : self::integerValue($var) !== null,
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

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function canCoerceType(
        NativeReflectionType $type,
        mixed $value,
        ?ReflectionClass $scope,
    ): bool {
        if ($type instanceof ReflectionNamedType) {
            return self::canCoerceNamedType($type, $value, $scope);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return self::matchType($type, $value, strict: true, scope: $scope);
        }

        if ($type instanceof ReflectionUnionType) {
            if (self::matchType($type, $value, strict: true, scope: $scope)) {
                return true;
            }

            $coercible = 0;
            foreach ($type->getTypes() as $subType) {
                if (!self::canCoerceType($subType, $value, $scope)) {
                    continue;
                }

                ++$coercible;
                if ($coercible > 1) {
                    return false;
                }
            }

            return $coercible === 1;
        }

        throw new InvalidArgumentException(sprintf('Unsupported type: %s', $type::class));
    }

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function canCoerceNamedType(
        ReflectionNamedType $type,
        mixed $value,
        ?ReflectionClass $scope,
    ): bool {
        if ($value === null) {
            return $type->allowsNull();
        }

        if (!$type->isBuiltin()) {
            return self::matchType($type, $value, strict: true, scope: $scope);
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int' => is_bool($value) || self::integerValue($value) !== null,
            'float' => is_int($value)
                || is_float($value)
                || is_bool($value)
                || (is_string($value) && is_numeric($value)),
            'string' => is_scalar($value) || $value instanceof Stringable,
            'bool' => is_scalar($value),
            'array' => is_array($value) || is_iterable($value) || $value instanceof Arrayable,
            'object' => is_object($value) || is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'null', 'false', 'true', 'resource' => self::matchBuiltinType($type->getName(), $value, strict: true),
            'never', 'void' => false,
            default => self::matchBuiltinType($type->getName(), $value, strict: true),
        };
    }

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function coerceType(
        NativeReflectionType $type,
        mixed $value,
        ?ReflectionClass $scope,
    ): mixed {
        if ($type instanceof ReflectionNamedType) {
            return self::coerceNamedType($type, $value, $scope);
        }

        if ($type instanceof ReflectionIntersectionType) {
            if (self::matchType($type, $value, strict: true, scope: $scope)) {
                return $value;
            }

            throw self::coercionException($type, $value);
        }

        if ($type instanceof ReflectionUnionType) {
            if (self::matchType($type, $value, strict: true, scope: $scope)) {
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

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function coerceNamedType(
        ReflectionNamedType $type,
        mixed $value,
        ?ReflectionClass $scope,
    ): mixed {
        if ($value === null && $type->allowsNull()) {
            return null;
        }

        if (!$type->isBuiltin()) {
            if (self::matchType($type, $value, strict: true, scope: $scope)) {
                return $value;
            }

            throw self::coercionException($type, $value);
        }

        $name = $type->getName();
        if ($name === 'int') {
            return (is_bool($value) ? (int) $value : self::integerValue($value))
                ?? throw self::coercionException($type, $value);
        }

        if (!self::canCoerceNamedType($type, $value, $scope)) {
            throw self::coercionException($type, $value);
        }

        return match ($name) {
            'float' => self::coerceToFloat($value),
            'string' => self::coerceToString($value),
            'bool' => (bool) $value,
            'array' => self::coerceToArray($value),
            'object' => is_object($value) ? $value : (object) $value,
            default => $value,
        };
    }

    private static function integerValue(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) && self::floatFitsInt($value) => (int) $value,
            is_string($value) => self::integerFromString($value),
            default => null,
        };
    }

    private static function floatFitsInt(float $value): bool
    {
        // On 64-bit PHP the floating-point comparison rounds PHP_INT_MAX up.
        return is_finite($value) && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX
            && $value < -(float) PHP_INT_MIN;
    }

    private static function integerFromString(string $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = +$value;
        if (is_int($number)) {
            return $number;
        }

        // Decimal and scientific notation need exact digit arithmetic near the platform limits.
        $value = trim($value, " \t\n\r\v\f");
        if (preg_match('/\A([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?\z/', $value, $parts) !== 1) {
            return null;
        }
        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2] . $fraction, '0');
        if ($digits === '') {
            return 0;
        }

        $negative = $parts[1] === '-';
        $limit = $negative
            ? substr((string) PHP_INT_MIN, 1)
            : (string) PHP_INT_MAX;
        $limitLength = strlen($limit);
        $exponent = (int) ($parts[4] ?? '0');
        $wholeLength = strlen($digits) - strlen($fraction);

        // Check the exponent before adding it or allocating any zero padding.
        if ($exponent <= -$wholeLength) {
            return 0;
        }
        if ($exponent > $limitLength - $wholeLength) {
            return null;
        }
        $wholeLength += $exponent;
        $whole = str_pad(substr($digits, 0, $wholeLength), $wholeLength, '0');
        if ($wholeLength === $limitLength) {
            $comparison = strcmp($whole, $limit);
            if ($comparison > 0 || ($comparison === 0 && trim(substr($digits, $wholeLength), '0') !== '')) {
                return null;
            }
        }

        return (int) ($negative ? '-' . $whole : $whole);
    }

    private static function coerceToFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value) || is_bool($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Value of type %s cannot be coerced to float.',
            get_debug_type($value),
        ));
    }

    private static function coerceToString(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Value of type %s cannot be coerced to string.',
            get_debug_type($value),
        ));
    }

    /** @return array<array-key, mixed> */
    private static function coerceToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_iterable($value)) {
            try {
                return iterator_to_array($value);
            } catch (TypeError $error) {
                if ($error->getFile() !== __FILE__ || ($error->getTrace()[0]['function'] ?? null) !== 'iterator_to_array') {
                    throw $error;
                }

                throw new InvalidArgumentException('Cannot coerce iterable with an invalid array key.', previous: $error);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Value of type %s cannot be coerced to array.',
            get_debug_type($value),
        ));
    }

    /**
     * @param ReflectionClass<covariant object>|null $scope
     * @param list<string> $names
     */
    private static function collectTypeNames(
        NativeReflectionType $type,
        ?ReflectionClass $scope,
        array &$names,
    ): void {
        if ($type instanceof ReflectionNamedType) {
            $name = self::resolveNamedTypeName($type, $scope, requireContext: false);
            $names[] = $name;

            if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
                $names[] = 'null';
            }

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

    /** @param ReflectionClass<covariant object>|null $scope */
    private static function resolveNamedTypeName(
        ReflectionNamedType $type,
        ?ReflectionClass $scope,
        bool $requireContext,
    ): string {
        $name = $type->getName();
        if ($name !== 'self' && $name !== 'parent' && $name !== 'static') {
            return $name;
        }

        if ($scope === null) {
            if ($requireContext) {
                throw new InvalidArgumentException(sprintf(
                    'Type "%s" requires declaring class context.',
                    $name,
                ));
            }

            return $name;
        }

        if ($scope->isTrait()) {
            throw new InvalidArgumentException(sprintf(
                'Type "%s" cannot be resolved from trait %s without the consuming class context.',
                $name,
                $scope->getName(),
            ));
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

    /**
     * @param ReflectionClass<covariant object>|class-string|null $scope
     * @return ReflectionClass<covariant object>|null
     */
    private static function normalizeScope(ReflectionClass|string|null $scope): ?ReflectionClass
    {
        if ($scope === null || $scope instanceof ReflectionClass) {
            return $scope;
        }

        $reflection = Reflection::class($scope);
        if ($reflection === null) {
            throw new InvalidArgumentException(sprintf(
                'Invalid reflection type scope: %s.',
                $scope,
            ));
        }

        return $reflection;
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
