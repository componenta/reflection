<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionFunction;

function intReflectionType(): \ReflectionType
{
    return (new ReflectionFunction(static fn (int $value) => null))->getParameters()[0]->getType()
        ?? throw new \LogicException('Expected reflected int type.');
}

test('integer coercion rejects non-finite and overflowing numeric values', function (): void {
    $type = intReflectionType();

    foreach ([INF, -INF, NAN, 1.0e20, -1.0e20, '1e20'] as $value) {
        expect(ReflectionType::canCoerce($type, $value))->toBeFalse();
        expect(fn () => ReflectionType::coerce($type, $value))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('integer coercion preserves supported weak numeric conversions', function (): void {
    $type = intReflectionType();

    expect(ReflectionType::canCoerce($type, 3.7))->toBeTrue()
        ->and(ReflectionType::coerce($type, 3.7))->toBe(3)
        ->and(ReflectionType::canCoerce($type, '3.7'))->toBeTrue()
        ->and(ReflectionType::coerce($type, '3.7'))->toBe(3)
        ->and(ReflectionType::canCoerce($type, true))->toBeTrue()
        ->and(ReflectionType::coerce($type, true))->toBe(1)
        ->and(ReflectionType::coerce($type, (string) PHP_INT_MAX))->toBe(PHP_INT_MAX)
        ->and(ReflectionType::coerce($type, (string) PHP_INT_MIN))->toBe(PHP_INT_MIN);
});

test('loose integer matching rejects values outside the platform integer range', function (): void {
    $type = intReflectionType();

    expect(ReflectionType::match($type, '42'))->toBeTrue()
        ->and(ReflectionType::match($type, 3.7))->toBeTrue()
        ->and(ReflectionType::match($type, INF))->toBeFalse()
        ->and(ReflectionType::match($type, NAN))->toBeFalse()
        ->and(ReflectionType::match($type, '1e20'))->toBeFalse();
});
