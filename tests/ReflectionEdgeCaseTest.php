<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Attribute;
use Closure;
use Componenta\Reflection\Reflection;
use ErrorException;
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

class EdgeCallableAncestor
{
    public function select(string $value = 'ancestor'): string
    {
        return $value;
    }

    public static function selectStatic(string $value = 'ancestor static'): string
    {
        return $value;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return 'magic';
    }
}

final class EdgeCallableChild extends EdgeCallableAncestor
{
    public function select(string $value = 'child'): string
    {
        return $value;
    }
}

test('a string callable with a separator inside its class alias reflects the concrete method', function (): void {
    $alias = EdgeCallableAncestor::class . '::Alias';
    class_alias(EdgeCallableAncestor::class, $alias);
    $callable = $alias . '::selectStatic';
    if (!is_callable($callable)) {
        throw new \LogicException('Expected a native static callable.');
    }

    $method = Reflection::callable($callable);

    expect($method->getParameters()[0]->getDefaultValue())->toBe('ancestor static')
        ->and(Reflection::reflect($callable))->toBe($method)
        ->and(Reflection::callable([$alias, 'selectStatic']))->toBe($method);
});

test('qualified callables reflect the declaration selected by PHP', function (): void {
    $target = new EdgeCallableChild();
    $ancestor = [$target, EdgeCallableAncestor::class . '::select'];
    $static = [EdgeCallableChild::class, EdgeCallableAncestor::class . '::selectStatic'];
    $magic = [$target, EdgeCallableAncestor::class . '::missing'];
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        if ($level === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        foreach ([[$ancestor, 'ancestor'], [$static, 'ancestor static']] as [$input, $default]) {
            if (!is_callable($input)) {
                throw new \LogicException('Expected a native qualified callable.');
            }
            expect(Closure::fromCallable($input)())->toBe($default)
                ->and(Reflection::callable($input)->getParameters()[0]->getDefaultValue())->toBe($default);
        }
        expect(Reflection::callable([$target, 'select'])->getParameters()[0]->getDefaultValue())->toBe('child')
            ->and(Reflection::reflect($magic))->toBeNull();
    } finally {
        restore_error_handler();
    }
});

test('class reflection reuses an instance published by its own autoloader', function (): void {
    $target = __NAMESPACE__ . '\\ReflectionDefinedDuringLookup';
    $inside = null;
    $autoload = static function (string $name) use ($target, &$inside): void {
        if ($name === $target) {
            class_alias(EdgeAnnotatedClass::class, $target);
            $inside = Reflection::class($target);
        }
    };
    spl_autoload_register($autoload);
    try {
        $outside = Reflection::class($target);
    } finally {
        spl_autoload_unregister($autoload);
    }

    expect($outside?->getName())->toBe(EdgeAnnotatedClass::class)
        ->and($outside)->toBe($inside)
        ->and(Reflection::class(strtolower($target)))->toBe($outside);
});

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
