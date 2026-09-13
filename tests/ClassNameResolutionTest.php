<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests\ClassNameResolution;

use Componenta\Reflection\Reflection;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;

final class Target
{
    public function accept(self $value): void
    {
    }
}

test('rejects repeated leading separators before and after a valid class lookup', function (int $separators): void {
    Reflection::clearReflectors();
    $invalid = str_repeat('\\', $separators) . Target::class;

    expect(Reflection::class($invalid))->toBeNull();
    expect(Reflection::class(Target::class)?->getName())->toBe(Target::class);
    expect(Reflection::class($invalid))->toBeNull();
})->with([2, 3]);

test('resolves valid class spellings before and after cache warming', function (): void {
    Reflection::clearReflectors();
    $spellings = [Target::class, '\\' . Target::class, strtolower(Target::class), '\\' . strtoupper(Target::class)];

    foreach ($spellings as $name) {
        expect(Reflection::class($name)?->getName())->toBe(Target::class);
    }
});

test('preserves aliases accepted by PHP outside identifier syntax', function (): void {
    Reflection::clearReflectors();
    $alias = __NAMESPACE__ . '\\alias-with-dash';
    if (!class_exists($alias, false)) {
        class_alias(Target::class, $alias);
    }

    expect(Reflection::class($alias)?->getName())->toBe(Target::class)
        ->and(Reflection::class('\\' . strtoupper($alias))?->getName())->toBe(Target::class);
});

test('rejects invalid relative type scopes regardless of prior class lookups', function (bool $warm): void {
    Reflection::clearReflectors();
    $type = new ReflectionMethod(Target::class, 'accept')->getParameters()[0]->getType()
        ?? throw new LogicException('The fixture must declare a parameter type.');
    $invalid = str_repeat('\\', 2) . Target::class;
    if ($warm) {
        Reflection::class(Target::class);
    }

    expect(fn () => ReflectionType::match($type, new Target(), strict: true, scope: $invalid))
        ->toThrow(InvalidArgumentException::class, 'Invalid reflection type scope');
    expect(fn () => ReflectionType::coerce($type, new Target(), scope: $invalid))
        ->toThrow(InvalidArgumentException::class, 'Invalid reflection type scope');
})->with(['cold' => false, 'warm' => true]);
