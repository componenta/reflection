<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Componenta\Arrayable\Arrayable;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionType as NativeReflectionType;
use Stringable;

function parameterType(callable $callable, string $parameter = 'value'): NativeReflectionType
{
    $reflection = new ReflectionFunction($callable);
    foreach ($reflection->getParameters() as $reflectionParameter) {
        if ($reflectionParameter->getName() === $parameter) {
            return $reflectionParameter->getType()
                ?? throw new InvalidArgumentException(sprintf('Parameter %s has no type.', $parameter));
        }
    }

    throw new InvalidArgumentException(sprintf('Parameter %s was not found.', $parameter));
}

test('matches named PHP types with strict and non-strict semantics', function (): void {
    $int = parameterType(static fn (int $value) => null);
    $float = parameterType(static fn (float $value) => null);
    $string = parameterType(static fn (string $value) => null);
    $nullableInt = parameterType(static fn (?int $value) => null);

    $stringable = new StringableValue('value');

    expect(ReflectionType::match($int, 42, strict: true))->toBeTrue()
        ->and(ReflectionType::match($int, '42'))->toBeTrue()
        ->and(ReflectionType::match($int, '42', strict: true))->toBeFalse()
        ->and(ReflectionType::match($float, 42))->toBeTrue()
        ->and(ReflectionType::match($float, 42, strict: true))->toBeFalse()
        ->and(ReflectionType::match($string, $stringable))->toBeTrue()
        ->and(ReflectionType::match($string, $stringable, strict: true))->toBeFalse()
        ->and(ReflectionType::match($nullableInt, null))->toBeTrue()
        ->and(ReflectionType::match($int, null))->toBeFalse();
});

test('matches builtin, literal and class types', function (): void {
    $mixed = parameterType(static fn (mixed $value) => null);
    $callable = parameterType(static fn (callable $value) => null);
    $iterable = parameterType(static fn (iterable $value) => null);
    $true = parameterType(static fn (true $value) => null);
    $false = parameterType(static fn (false $value) => null);
    $class = parameterType(static fn (\stdClass $value) => null);

    expect(ReflectionType::match($mixed, null, strict: true))->toBeTrue()
        ->and(ReflectionType::match($mixed, ['anything'], strict: true))->toBeTrue()
        ->and(ReflectionType::match($callable, static fn () => null, strict: true))->toBeTrue()
        ->and(ReflectionType::match($iterable, new \ArrayIterator([1]), strict: true))->toBeTrue()
        ->and(ReflectionType::match($true, true, strict: true))->toBeTrue()
        ->and(ReflectionType::match($true, false, strict: true))->toBeFalse()
        ->and(ReflectionType::match($false, false, strict: true))->toBeTrue()
        ->and(ReflectionType::match($class, new \stdClass(), strict: true))->toBeTrue()
        ->and(ReflectionType::match($class, new \ArrayObject(), strict: true))->toBeFalse();
});

test('matches unions and intersections', function (): void {
    $union = parameterType(static fn (int|string $value) => null);
    $intersection = parameterType(static fn (FirstType&SecondType $value) => null);

    expect(ReflectionType::match($union, 42, strict: true))->toBeTrue()
        ->and(ReflectionType::match($union, 'value', strict: true))->toBeTrue()
        ->and(ReflectionType::match($union, new \stdClass(), strict: true))->toBeFalse()
        ->and(ReflectionType::match($intersection, new BothTypes(), strict: true))->toBeTrue()
        ->and(ReflectionType::match($intersection, new FirstOnly(), strict: true))->toBeFalse();
});

test('flattens and matches DNF types recursively', function (): void {
    $type = parameterType(static fn ((FirstType&SecondType)|ThirdType $value) => null);

    expect(ReflectionType::getTypeNames($type))
        ->toHaveCount(3)
        ->toContain(FirstType::class, SecondType::class, ThirdType::class)
        ->and(ReflectionType::contains($type, FirstType::class))->toBeTrue()
        ->and(ReflectionType::contains($type, SecondType::class))->toBeTrue()
        ->and(ReflectionType::contains($type, ThirdType::class))->toBeTrue()
        ->and(ReflectionType::match($type, new BothTypes(), strict: true))->toBeTrue()
        ->and(ReflectionType::match($type, new ThirdOnly(), strict: true))->toBeTrue()
        ->and(ReflectionType::match($type, new FirstOnly(), strict: true))->toBeFalse();
});

test('uses the native canonical type string including DNF parentheses', function (): void {
    $type = parameterType(static fn ((FirstType&SecondType)|ThirdType $value) => null);

    expect(ReflectionType::toString($type))->toBe((string) $type)
        ->and(ReflectionType::toString($type))->toContain('(')->toContain(')');
});

test('resolves self and parent against an explicit declaring scope', function (): void {
    $selfMethod = new ReflectionMethod(ChildScope::class, 'acceptSelf');
    $parentMethod = new ReflectionMethod(ChildScope::class, 'acceptParent');
    $selfType = $selfMethod->getParameters()[0]->getType();
    $parentType = $parentMethod->getParameters()[0]->getType();
    $scope = new ReflectionClass(ChildScope::class);

    expect($selfType)->not->toBeNull()
        ->and($parentType)->not->toBeNull()
        ->and(ReflectionType::match($selfType, new ChildScope(), strict: true, scope: $scope))->toBeTrue()
        ->and(ReflectionType::match($selfType, new BaseScope(), strict: true, scope: $scope))->toBeFalse()
        ->and(ReflectionType::match($parentType, new BaseScope(), strict: true, scope: $scope))->toBeTrue()
        ->and(ReflectionType::contains($selfType, ChildScope::class, $scope))->toBeTrue()
        ->and(ReflectionType::contains($parentType, BaseScope::class, $scope))->toBeTrue();
});

test('handles native self type resolution without explicit scope', function (): void {
    $type = (new ReflectionMethod(ChildScope::class, 'acceptSelf'))->getParameters()[0]->getType();

    expect($type)->not->toBeNull();

    if ((string) $type === 'self') {
        expect(static fn () => ReflectionType::match($type, new ChildScope()))
            ->toThrow(InvalidArgumentException::class, 'requires declaring class context');
        return;
    }

    expect(ReflectionType::match($type, new ChildScope(), strict: true))->toBeTrue();
});

test('coerces supported native PHP values and agrees with canCoerce', function (): void {
    $int = parameterType(static fn (int $value) => null);
    $float = parameterType(static fn (float $value) => null);
    $string = parameterType(static fn (string $value) => null);
    $bool = parameterType(static fn (bool $value) => null);
    $array = parameterType(static fn (array $value) => null);
    $object = parameterType(static fn (object $value) => null);

    $stringable = new StringableValue('text');
    $iterator = new \ArrayIterator([1, 2]);
    $arrayable = new ArrayableValue(['a' => 1]);

    expect(ReflectionType::canCoerce($int, '42'))->toBeTrue()
        ->and(ReflectionType::coerce($int, '42'))->toBe(42)
        ->and(ReflectionType::canCoerce($float, '3.5'))->toBeTrue()
        ->and(ReflectionType::coerce($float, '3.5'))->toBe(3.5)
        ->and(ReflectionType::canCoerce($string, $stringable))->toBeTrue()
        ->and(ReflectionType::coerce($string, $stringable))->toBe('text')
        ->and(ReflectionType::canCoerce($bool, 'yes'))->toBeTrue()
        ->and(ReflectionType::coerce($bool, 'yes'))->toBeTrue()
        ->and(ReflectionType::canCoerce($array, $iterator))->toBeTrue()
        ->and(ReflectionType::coerce($array, $iterator))->toBe([1, 2])
        ->and(ReflectionType::canCoerce($array, $arrayable))->toBeTrue()
        ->and(ReflectionType::coerce($array, $arrayable))->toBe(['a' => 1])
        ->and(ReflectionType::canCoerce($object, ['name' => 'value']))->toBeTrue()
        ->and(ReflectionType::coerce($object, ['name' => 'value']))->toBeInstanceOf(\stdClass::class);
});

test('canCoerce false means coerce rejects the same conversion', function (): void {
    $array = parameterType(static fn (array $value) => null);
    $string = parameterType(static fn (string $value) => null);
    $int = parameterType(static fn (int $value) => null);
    $class = parameterType(static fn (\stdClass $value) => null);

    expect(ReflectionType::canCoerce($array, 42))->toBeFalse()
        ->and(static fn () => ReflectionType::coerce($array, 42))->toThrow(InvalidArgumentException::class)
        ->and(ReflectionType::canCoerce($string, null))->toBeFalse()
        ->and(static fn () => ReflectionType::coerce($string, null))->toThrow(InvalidArgumentException::class)
        ->and(ReflectionType::canCoerce($int, 'not numeric'))->toBeFalse()
        ->and(static fn () => ReflectionType::coerce($int, 'not numeric'))->toThrow(InvalidArgumentException::class)
        ->and(ReflectionType::canCoerce($class, new \ArrayObject()))->toBeFalse()
        ->and(static fn () => ReflectionType::coerce($class, new \ArrayObject()))->toThrow(InvalidArgumentException::class);
});

test('nullable coercion preserves null', function (): void {
    $nullableInt = parameterType(static fn (?int $value) => null);

    expect(ReflectionType::canCoerce($nullableInt, null))->toBeTrue()
        ->and(ReflectionType::coerce($nullableInt, null))->toBeNull();
});

test('union coercion succeeds only when exactly one conversion target is possible', function (): void {
    $unique = parameterType(static fn (int|array $value) => null);
    $ambiguous = parameterType(static fn (int|float $value) => null);

    expect(ReflectionType::canCoerce($unique, '42'))->toBeTrue()
        ->and(ReflectionType::coerce($unique, '42'))->toBe(42)
        ->and(ReflectionType::canCoerce($ambiguous, '42'))->toBeFalse()
        ->and(static fn () => ReflectionType::coerce($ambiguous, '42'))
        ->toThrow(InvalidArgumentException::class, 'ambiguous coercion');
});

test('contains handles missing reflection types', function (): void {
    expect(ReflectionType::contains(null, 'int'))->toBeFalse()
        ->and(ReflectionType::match(null, new \stdClass()))->toBeTrue();
});

final class StringableValue implements Stringable
{
    public function __construct(private readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

final class ArrayableValue implements Arrayable
{
    /** @param array<array-key, mixed> $value */
    public function __construct(private readonly array $value) {}

    public function toArray(): array
    {
        return $this->value;
    }
}

interface FirstType {}
interface SecondType {}
interface ThirdType {}

final class BothTypes implements FirstType, SecondType {}
final class FirstOnly implements FirstType {}
final class ThirdOnly implements ThirdType {}

class BaseScope {}

final class ChildScope extends BaseScope
{
    public function acceptSelf(self $value): void {}
    public function acceptParent(parent $value): void {}
}
