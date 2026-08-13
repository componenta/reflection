<?php

declare(strict_types=1);

use Componenta\Reflection\Reflection;

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
