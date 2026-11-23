<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Componenta\Reflection\ReflectionType;
use ErrorException;
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

test('decimal and scientific strings preserve the integer value and range', function (string $value, int $expected): void {
    $type = intReflectionType();

    expect(ReflectionType::match($type, $value))->toBeTrue()
        ->and(ReflectionType::canCoerce($type, $value))->toBeTrue()
        ->and(ReflectionType::coerce($type, $value))->toBe($expected);
})->with([
    'maximum with decimals' => [PHP_INT_MAX . '.0', PHP_INT_MAX],
    'minimum in scientific notation' => [PHP_INT_MIN . '0e-1', PHP_INT_MIN],
    'truncate near minimum' => [(PHP_INT_MIN + 1) . '.75', PHP_INT_MIN + 1],
    'truncate through a positive exponent' => ["\f +.00379e+3 \t", 3],
    'zero with a large exponent' => ['0e999999999999999999999', 0],
    'tiny with a large negative exponent' => ['-2e-999999999999999999999', 0],
]);

test('an out of range integer is rejected without casting warnings', function (string|float $value): void {
    $type = intReflectionType();
    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        expect(ReflectionType::match($type, $value))->toBeFalse()
            ->and(ReflectionType::canCoerce($type, $value))->toBeFalse()
            ->and(fn () => ReflectionType::coerce($type, $value))->toThrow(InvalidArgumentException::class);
    } finally {
        restore_error_handler();
    }
})->with([
    'fraction above maximum' => [PHP_INT_MAX . '.1'],
    'fraction below minimum' => [PHP_INT_MIN . '.1'],
    'scientific value below minimum' => [PHP_INT_MIN . '1e-1'],
    'huge positive exponent' => ['2e999999999999999999999'],
    'float beyond maximum' => [-(float) PHP_INT_MIN],
]);
