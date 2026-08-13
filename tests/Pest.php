<?php

declare(strict_types=1);

use Componenta\Reflection\Reflection;

final class ReflectionMagicCallableFixture
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

beforeEach(function (): void {
    Reflection::clearReflectors();
});

test('metadata cache separates unfiltered and empty-name lookups', function (): void {
    $reflector = new ReflectionClass(Componenta\Reflection\Tests\AnnotatedClass::class);

    expect(Reflection::getMetadata($reflector))->toHaveCount(2)
        ->and(Reflection::getMetadata($reflector, ''))->toBeNull();

    Reflection::clearReflectors();
    $reflector = new ReflectionClass(Componenta\Reflection\Tests\AnnotatedClass::class);

    expect(Reflection::getMetadata($reflector, ''))->toBeNull()
        ->and(Reflection::getMetadata($reflector))->toHaveCount(2);
});

test('ReflectionConstant metadata is safe across supported PHP versions', function (): void {
    $name = 'COMPONENTA_REFLECTION_UNANNOTATED_CONSTANT';
    if (!defined($name)) {
        define($name, 1);
    }

    $reflector = new ReflectionConstant($name);

    expect(Reflection::getMetadata($reflector))->toBeNull();
});

test('magic-dispatch callables have no concrete method reflection', function (): void {
    $object = new ReflectionMagicCallableFixture();
    $instanceCallable = [$object, 'missing'];
    $staticCallable = ReflectionMagicCallableFixture::class . '::missing';

    expect(is_callable($instanceCallable))->toBeTrue()
        ->and(is_callable($staticCallable))->toBeTrue();

    expect(fn () => Reflection::callable($instanceCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');
    expect(fn () => Reflection::callable($staticCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');

    expect(Reflection::reflect($instanceCallable))->toBeNull()
        ->and(Reflection::reflect($staticCallable))->toBeNull();
});
