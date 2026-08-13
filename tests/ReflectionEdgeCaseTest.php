<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Attribute;
use Componenta\Reflection\Reflection;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionConstant;

#[Attribute]
final class EdgeAttribute {}

#[EdgeAttribute]
final class EdgeAnnotatedClass {}

interface EdgeInterface {}
trait EdgeTrait {}
enum EdgeEnum { case Value; }

final class EdgeMagicCallable
{
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return null;
    }
}

test('metadata cache separates unfiltered and empty-name lookups', function (): void {
    $reflector = new ReflectionClass(EdgeAnnotatedClass::class);

    expect(Reflection::getMetadata($reflector))->toHaveCount(1)
        ->and(Reflection::getMetadata($reflector, ''))->toBeNull();

    Reflection::clearReflectors();
    $reflector = new ReflectionClass(EdgeAnnotatedClass::class);

    expect(Reflection::getMetadata($reflector, ''))->toBeNull()
        ->and(Reflection::getMetadata($reflector))->toHaveCount(1);
});

test('ReflectionConstant metadata is safe across supported PHP versions', function (): void {
    $name = __NAMESPACE__ . '\\EDGE_UNANNOTATED_CONSTANT';
    if (!defined($name)) {
        define($name, 1);
    }

    expect(Reflection::getMetadata(new ReflectionConstant($name)))->toBeNull();
});

test('class reflection supports all class-like symbols', function (): void {
    expect(Reflection::class(EdgeAnnotatedClass::class)?->getName())->toBe(EdgeAnnotatedClass::class)
        ->and(Reflection::class(EdgeInterface::class)?->getName())->toBe(EdgeInterface::class)
        ->and(Reflection::class(EdgeTrait::class)?->getName())->toBe(EdgeTrait::class)
        ->and(Reflection::class(EdgeEnum::class)?->getName())->toBe(EdgeEnum::class);
});

test('missing class-like symbols invoke the autoloader once', function (): void {
    $target = __NAMESPACE__ . '\\DefinitelyMissingEdgeSymbol';
    $calls = 0;
    $loader = static function (string $class) use ($target, &$calls): void {
        if ($class === $target) {
            ++$calls;
        }
    };

    spl_autoload_register($loader, prepend: true);
    try {
        expect(Reflection::class($target))->toBeNull()
            ->and($calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('magic-dispatch callables have no concrete method reflection', function (): void {
    $object = new EdgeMagicCallable();
    $instanceCallable = [$object, 'missing'];
    $staticCallable = EdgeMagicCallable::class . '::missing';

    expect(is_callable($instanceCallable))->toBeTrue()
        ->and(is_callable($staticCallable))->toBeTrue();

    expect(fn () => Reflection::callable($instanceCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');
    expect(fn () => Reflection::callable($staticCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');

    expect(Reflection::reflect($instanceCallable))->toBeNull()
        ->and(Reflection::reflect($staticCallable))->toBeNull();
});
