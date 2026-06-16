<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use Componenta\Reflection\ReflectionType;
use Stringable;

final class ReflectionTypeTest extends TestCase
{
    public function testMatchReturnsTrueForIntegerWithIntType(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 42));
    }

    public function testMatchReturnsTrueForNumericStringWithIntTypeNonStrict(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, '42', strict: false));
    }

    public function testMatchReturnsFalseForNumericStringWithIntTypeStrict(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertFalse(ReflectionType::match($type, '42', strict: true));
    }

    public function testMatchReturnsTrueForFloatWithFloatType(): void
    {
        $type = $this->getParameterType(fn(float $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 3.14));
    }

    public function testMatchReturnsTrueForIntegerWithFloatTypeNonStrict(): void
    {
        $type = $this->getParameterType(fn(float $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 42, strict: false));
    }

    public function testMatchReturnsFalseForIntegerWithFloatTypeStrict(): void
    {
        $type = $this->getParameterType(fn(float $x) => null, 'x');

        $this->assertFalse(ReflectionType::match($type, 42, strict: true));
    }

    public function testMatchReturnsTrueForStringWithStringType(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 'hello'));
    }

    public function testMatchReturnsTrueForStringableWithStringTypeNonStrict(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');
        $stringable = new StringableObject('test');

        $this->assertTrue(ReflectionType::match($type, $stringable, strict: false));
    }

    public function testMatchReturnsFalseForStringableWithStringTypeStrict(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');
        $stringable = new StringableObject('test');

        $this->assertFalse(ReflectionType::match($type, $stringable, strict: true));
    }

    public function testMatchReturnsTrueForBoolWithBoolType(): void
    {
        $type = $this->getParameterType(fn(bool $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, true));
        $this->assertTrue(ReflectionType::match($type, false));
    }

    public function testMatchReturnsTrueForArrayWithArrayType(): void
    {
        $type = $this->getParameterType(fn(array $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, [1, 2, 3]));
    }

    public function testMatchReturnsTrueForObjectWithObjectType(): void
    {
        $type = $this->getParameterType(fn(object $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, new \stdClass()));
    }

    public function testMatchReturnsTrueForNullWithNullableType(): void
    {
        $type = $this->getParameterType(fn(?int $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, null));
    }

    public function testMatchReturnsFalseForNullWithNonNullableType(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertFalse(ReflectionType::match($type, null));
    }

    public function testMatchReturnsFalseForStringWithIntType(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertFalse(ReflectionType::match($type, 'not a number', strict: true));
    }

    public function testMatchReturnsTrueForAnythingWithMixedType(): void
    {
        $type = $this->getParameterType(fn(mixed $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 42));
        $this->assertTrue(ReflectionType::match($type, 'string'));
        $this->assertTrue(ReflectionType::match($type, null));
        $this->assertTrue(ReflectionType::match($type, []));
    }

    public function testMatchReturnsTrueForCallableWithCallableType(): void
    {
        $type = $this->getParameterType(fn(callable $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, fn() => null));
        $this->assertTrue(ReflectionType::match($type, 'strlen'));
    }

    public function testMatchReturnsTrueForIterableWithIterableType(): void
    {
        $type = $this->getParameterType(fn(iterable $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, [1, 2, 3]));
        $this->assertTrue(ReflectionType::match($type, new \ArrayIterator([1, 2])));
    }

    public function testMatchReturnsTrueForTrueWithTrueType(): void
    {
        $type = $this->getParameterType(fn(true $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, true));
        $this->assertFalse(ReflectionType::match($type, false));
    }

    public function testMatchReturnsTrueForFalseWithFalseType(): void
    {
        $type = $this->getParameterType(fn(false $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, false));
        $this->assertFalse(ReflectionType::match($type, true));
    }

    public function testMatchReturnsTrueForInstanceOfClass(): void
    {
        $type = $this->getParameterType(fn(\stdClass $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, new \stdClass()));
        $this->assertFalse(ReflectionType::match($type, new \ArrayObject()));
    }

    public function testMatchUnionTypeMatchesAnyType(): void
    {
        $type = $this->getParameterType(fn(int|string $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, 42));
        $this->assertTrue(ReflectionType::match($type, 'hello'));
        $this->assertFalse(ReflectionType::match($type, 3.14, strict: true));
    }

    public function testMatchIntersectionTypeRequiresAllTypes(): void
    {
        $type = $this->getParameterType(fn(FirstInterface&SecondInterface $x) => null, 'x');

        $this->assertTrue(ReflectionType::match($type, new ImplementsBoth()));
        $this->assertFalse(ReflectionType::match($type, new ImplementsFirst()));
    }

    public function testCanCoerceReturnsTrueForNullWithNullableType(): void
    {
        $type = $this->getParameterType(fn(?int $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, null));
    }

    public function testCanCoerceReturnsTrueForNumericStringToInt(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, '42'));
    }

    public function testCanCoerceReturnsTrueForBoolToInt(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, true));
    }

    public function testCanCoerceReturnsTrueForScalarToString(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, 42));
        $this->assertTrue(ReflectionType::canCoerce($type, 3.14));
        $this->assertTrue(ReflectionType::canCoerce($type, true));
    }

    public function testCanCoerceReturnsTrueForStringableToString(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');
        $stringable = new StringableObject('test');

        $this->assertTrue(ReflectionType::canCoerce($type, $stringable));
    }

    public function testCanCoerceReturnsTrueForScalarToBool(): void
    {
        $type = $this->getParameterType(fn(bool $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, 1));
        $this->assertTrue(ReflectionType::canCoerce($type, 'yes'));
    }

    public function testCanCoerceReturnsTrueForIterableToArray(): void
    {
        $type = $this->getParameterType(fn(array $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, new \ArrayIterator([1, 2])));
    }

    public function testCanCoerceReturnsTrueForArrayToObject(): void
    {
        $type = $this->getParameterType(fn(object $x) => null, 'x');

        $this->assertTrue(ReflectionType::canCoerce($type, ['key' => 'value']));
    }

    public function testCanCoerceReturnsFalseForArrayToCallable(): void
    {
        $type = $this->getParameterType(fn(callable $x) => null, 'x');

        $this->assertFalse(ReflectionType::canCoerce($type, ['not', 'callable']));
    }

    public function testCoerceConvertsToInt(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertSame(42, ReflectionType::coerce($type, '42'));
        $this->assertSame(1, ReflectionType::coerce($type, true));
    }

    public function testCoerceConvertsToFloat(): void
    {
        $type = $this->getParameterType(fn(float $x) => null, 'x');

        $this->assertSame(3.14, ReflectionType::coerce($type, '3.14'));
        $this->assertSame(42.0, ReflectionType::coerce($type, 42));
    }

    public function testCoerceConvertsToString(): void
    {
        $type = $this->getParameterType(fn(string $x) => null, 'x');

        $this->assertSame('42', ReflectionType::coerce($type, 42));
        $this->assertSame('1', ReflectionType::coerce($type, true));
    }

    public function testCoerceConvertsToBool(): void
    {
        $type = $this->getParameterType(fn(bool $x) => null, 'x');

        $this->assertTrue(ReflectionType::coerce($type, 1));
        $this->assertTrue(ReflectionType::coerce($type, 'yes'));
        $this->assertFalse(ReflectionType::coerce($type, 0));
        $this->assertFalse(ReflectionType::coerce($type, ''));
    }

    public function testCoerceConvertsIterableToArray(): void
    {
        $type = $this->getParameterType(fn(array $x) => null, 'x');
        $iterator = new \ArrayIterator([1, 2, 3]);

        $result = ReflectionType::coerce($type, $iterator);

        $this->assertSame([1, 2, 3], $result);
    }

    public function testCoerceConvertsArrayToObject(): void
    {
        $type = $this->getParameterType(fn(object $x) => null, 'x');

        $result = ReflectionType::coerce($type, ['key' => 'value']);

        $this->assertIsObject($result);
        $this->assertSame('value', $result->key);
    }

    public function testCoerceReturnsNullForNullableTypeWithNull(): void
    {
        $type = $this->getParameterType(fn(?int $x) => null, 'x');

        $this->assertNull(ReflectionType::coerce($type, null));
    }

    public function testCoerceReturnsValueUnchangedForNonBuiltinType(): void
    {
        $type = $this->getParameterType(fn(\stdClass $x) => null, 'x');
        $object = new \stdClass();

        $this->assertSame($object, ReflectionType::coerce($type, $object));
    }

    public function testCoerceWrapsScalarInArrayForArrayType(): void
    {
        $type = $this->getParameterType(fn(array $x) => null, 'x');

        $result = ReflectionType::coerce($type, 42);

        $this->assertSame([42], $result);
    }

    public function testCoerceReturnsEmptyArrayForNull(): void
    {
        $type = $this->getParameterType(fn(array $x) => null, 'x');

        $result = ReflectionType::coerce($type, null);

        $this->assertSame([], $result);
    }

    public function testToStringReturnsCorrectStringForNamedType(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertSame('int', ReflectionType::toString($type));
    }

    public function testToStringReturnsNullablePrefixForNullableType(): void
    {
        $type = $this->getParameterType(fn(?int $x) => null, 'x');

        $this->assertSame('?int', ReflectionType::toString($type));
    }

    public function testToStringReturnsUnionTypeSeparatedByPipe(): void
    {
        $type = $this->getParameterType(fn(int|string $x) => null, 'x');

        $result = ReflectionType::toString($type);

        $this->assertMatchesRegularExpression('/^(int\|string|string\|int)$/', $result);
    }

    public function testToStringReturnsIntersectionTypeSeparatedByAmpersand(): void
    {
        $type = $this->getParameterType(fn(FirstInterface&SecondInterface $x) => null, 'x');

        $result = ReflectionType::toString($type);

        $this->assertStringContainsString('&', $result);
        $this->assertStringContainsString('FirstInterface', $result);
        $this->assertStringContainsString('SecondInterface', $result);
        $this->assertStringNotContainsString('|', $result);
    }

    public function testToStringReturnsMixedWithoutNullablePrefix(): void
    {
        $type = $this->getParameterType(fn(mixed $x) => null, 'x');

        $this->assertSame('mixed', ReflectionType::toString($type));
    }

    public function testGetTypeNamesReturnsArrayWithSingleNameForNamedType(): void
    {
        $type = $this->getParameterType(fn(int $x) => null, 'x');

        $this->assertSame(['int'], ReflectionType::getTypeNames($type));
    }

    public function testGetTypeNamesReturnsAllTypesForUnionType(): void
    {
        $type = $this->getParameterType(fn(int|string|bool $x) => null, 'x');

        $names = ReflectionType::getTypeNames($type);

        $this->assertContains('int', $names);
        $this->assertContains('string', $names);
        $this->assertContains('bool', $names);
    }

    public function testGetTypeNamesReturnsAllTypesForIntersectionType(): void
    {
        $type = $this->getParameterType(fn(FirstInterface&SecondInterface $x) => null, 'x');

        $names = ReflectionType::getTypeNames($type);

        $this->assertCount(2, $names);
        $this->assertContains(FirstInterface::class, $names);
        $this->assertContains(SecondInterface::class, $names);
    }

    public function testContainsReturnsTrueWhenTypeNamePresent(): void
    {
        $type = $this->getParameterType(fn(int|string $x) => null, 'x');

        $this->assertTrue(ReflectionType::contains($type, 'int'));
        $this->assertTrue(ReflectionType::contains($type, 'string'));
    }

    public function testContainsReturnsFalseWhenTypeNameAbsent(): void
    {
        $type = $this->getParameterType(fn(int|string $x) => null, 'x');

        $this->assertFalse(ReflectionType::contains($type, 'bool'));
        $this->assertFalse(ReflectionType::contains($type, 'float'));
    }

    public function testGetTypeNamesReturnsEmptyArrayForUnsupportedType(): void
    {
        $mockType = $this->createMock(\ReflectionType::class);

        $result = ReflectionType::getTypeNames($mockType);

        $this->assertSame([], $result);
    }

    public function testMatchThrowsExceptionForUnsupportedReflectionType(): void
    {
        $mockType = $this->createMock(\ReflectionType::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported type');

        ReflectionType::match($mockType, 'value');
    }

    private function getParameterType(callable $fn, string $parameterName): \ReflectionType
    {
        $reflection = new ReflectionFunction($fn);
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getName() === $parameterName) {
                return $parameter->getType();
            }
        }
        throw new \RuntimeException("Parameter {$parameterName} not found");
    }
}

final class StringableObject implements Stringable
{
    public function __construct(private readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

interface FirstInterface {}

interface SecondInterface {}

final class ImplementsBoth implements FirstInterface, SecondInterface {}

final class ImplementsFirst implements FirstInterface {}
