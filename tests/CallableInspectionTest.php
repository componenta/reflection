<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests\CallableInspection;

use Componenta\Reflection\Reflection;
use Componenta\Reflection\ReflectionType;
use Error;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use ReflectionParameter;
use TypeError;

final class OriginalFactory
{
    public static function create(int &$value): string
    {
        return (string) $value;
    }

    public static function alternate(string $value): string
    {
        return $value;
    }
}

final class ReplacementFactory
{
    public static function create(string $value): string
    {
        return $value;
    }
}

interface StaticDispatch
{
    public static function __callStatic(string $name, array $arguments): mixed;
}

abstract class AbstractDispatch implements StaticDispatch
{
    public static function create(): object
    {
        return new \stdClass();
    }
}

final class ConcreteDispatch implements StaticDispatch
{
    public static function __callStatic(string $name, array $arguments): string
    {
        return $name;
    }
}

function callableType(): \ReflectionType
{
    return new ReflectionParameter(static fn (callable $value): mixed => $value, 0)->getType()
        ?? throw new LogicException('Expected a callable type.');
}

test('reflects the owner and method selected before callable autoload', function (bool $mixedInput, string $change): void {
    $alias = __NAMESPACE__ . '\\DeferredFactory_' . bin2hex(random_bytes(5));
    $owner = $alias;
    $method = 'create';
    $route = [&$owner, &$method];
    $autoload = static function (string $class) use ($alias, $change, &$owner, &$method): void {
        if ($class !== $alias) {
            return;
        }
        class_alias(OriginalFactory::class, $alias, autoload: false);
        if ($change === 'owner') {
            $owner = ReplacementFactory::class;
        } else {
            $method = 'alternate';
        }
    };
    spl_autoload_register($autoload);

    try {
        $reflection = $mixedInput ? Reflection::reflect($route) : Reflection::callable($route);
        expect($route)->toBe($change === 'owner' ? [ReplacementFactory::class, 'create'] : [$alias, 'alternate'])
            ->and($reflection)->toBeInstanceOf(ReflectionMethod::class)
            ->and($reflection->getName())->toBe('create')
            ->and($reflection->getDeclaringClass()->getName())->toBe(OriginalFactory::class)
            ->and((string) $reflection->getParameters()[0]->getType())->toBe('int')
            ->and($reflection->getParameters()[0]->isPassedByReference())->toBeTrue()
            ->and(Reflection::callable([1 => 'create', 0 => $alias]))->toBe($reflection);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['reflect' => true, 'callable' => false])->with(['owner', 'method']);

test('retains native rejection of an invalid callable array', function (array $input): void {
    expect(fn () => Reflection::callable($input))->toThrow(TypeError::class)
        ->and(Reflection::reflect($input))->toBeNull();
})->with([
    'unresolved method' => [[OriginalFactory::class, 'missing']],
    'invalid length' => [[OriginalFactory::class, 'create', 1]],
]);

test('reports no reflector for abstract static dispatch', function (bool $array): void {
    expect(Reflection::reflect(AbstractDispatch::class . '::create'))->toBeInstanceOf(ReflectionMethod::class);
    foreach ([StaticDispatch::class, AbstractDispatch::class] as $class) {
        $input = $array ? [$class, 'missing'] : $class . '::missing';
        expect(Reflection::reflect($input))->toBeNull();
    }
})->with(['array' => true, 'string' => false]);

test('treats an abstract static dispatch route as an invalid callable value', function (bool $array): void {
    $input = $array ? [StaticDispatch::class, 'missing'] : StaticDispatch::class . '::missing';
    $type = callableType();

    expect(ReflectionType::match($type, $input, strict: true))->toBeFalse()
        ->and(ReflectionType::canCoerce($type, $input))->toBeFalse()
        ->and(fn () => ReflectionType::coerce($type, $input))->toThrow(InvalidArgumentException::class);
})->with(['array' => true, 'string' => false]);

test('keeps a concrete magic route callable without a concrete reflector', function (): void {
    $input = [ConcreteDispatch::class, 'missing'];
    $type = callableType();

    expect(ReflectionType::match($type, $input, strict: true))->toBeTrue()
        ->and(ReflectionType::canCoerce($type, $input))->toBeTrue()
        ->and(ReflectionType::coerce($type, $input))->toBe($input)
        ->and(Reflection::reflect($input))->toBeNull();
});

test('preserves an application autoloader error with the same abstract method diagnostic', function (): void {
    $class = __NAMESPACE__ . '\\DeferredFactoryWithFailingLoader';
    $cause = new Error('Cannot call abstract method ' . StaticDispatch::class . '::missing()');
    $autoload = static function (string $name) use ($class, $cause): void {
        if ($name === $class) {
            throw $cause;
        }
    };
    spl_autoload_register($autoload);

    try {
        $input = [$class, 'missing'];
        $type = callableType();
        foreach ([
            static fn () => Reflection::reflect($input),
            static fn () => ReflectionType::match($type, $input, strict: true),
            static fn () => ReflectionType::canCoerce($type, $input),
            static fn () => ReflectionType::coerce($type, $input),
        ] as $probe) {
            try {
                $probe();
                throw new LogicException('Expected the autoloader error.');
            } catch (Error $error) {
                expect($error)->toBe($cause);
            }
        }
    } finally {
        spl_autoload_unregister($autoload);
    }
});
