<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

trait RelativeTypeTrait
{
    public function acceptSelf(self $value): void {}
}

final class RelativeTypeConsumer
{
    use RelativeTypeTrait;
}

test('type name normalization handles missing and nullable types', function (): void {
    $untyped = (new ReflectionFunction(static fn ($value) => null))->getParameters()[0]->getType();
    $nullable = (new ReflectionFunction(static fn (?int $value) => null))->getParameters()[0]->getType();
    $mixed = (new ReflectionFunction(static fn (mixed $value) => null))->getParameters()[0]->getType();

    expect($untyped)->toBeNull()
        ->and($nullable)->not->toBeNull()
        ->and($mixed)->not->toBeNull()
        ->and(ReflectionType::getTypeNames($untyped))->toBe([])
        ->and(ReflectionType::getTypeNames($nullable))->toBe(['int', 'null'])
        ->and(ReflectionType::contains($nullable, 'null'))->toBeTrue()
        ->and(ReflectionType::getTypeNames($mixed))->toBe(['mixed']);
});

test('trait relative types require a consuming class scope', function (): void {
    $traitMethod = new ReflectionMethod(RelativeTypeTrait::class, 'acceptSelf');
    $traitType = $traitMethod->getParameters()[0]->getType();
    $traitScope = new ReflectionClass(RelativeTypeTrait::class);

    expect($traitType)->not->toBeNull();

    expect(fn () => ReflectionType::getTypeNames($traitType, $traitScope))
        ->toThrow(InvalidArgumentException::class, 'consuming class context');

    $consumerMethod = new ReflectionMethod(RelativeTypeConsumer::class, 'acceptSelf');
    $consumerType = $consumerMethod->getParameters()[0]->getType();
    $consumerScope = new ReflectionClass(RelativeTypeConsumer::class);

    expect($consumerType)->not->toBeNull()
        ->and(ReflectionType::match(
            $consumerType,
            new RelativeTypeConsumer(),
            strict: true,
            scope: $consumerScope,
        ))->toBeTrue()
        ->and(ReflectionType::contains(
            $consumerType,
            RelativeTypeConsumer::class,
            $consumerScope,
        ))->toBeTrue();
});