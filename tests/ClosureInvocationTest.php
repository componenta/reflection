<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests\ClosureInvocation;

use Attribute;
use Componenta\Reflection\Reflection;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Label
{
    public function __construct(public string $value)
    {
    }
}

test('reflects the signature and attributes of each closure invoked as a method', function (string $method): void {
    $first = static fn (#[Label('first')] string $left = 'first-default'): string => $left;
    $second = static fn (#[Label('second')] string $right = 'second-default'): string => $right;

    foreach ([[$first, 'left', 'first'], [$second, 'right', 'second'], [$first, 'left', 'first']] as [$closure, $name, $label]) {
        $parameter = Reflection::callable([$closure, $method])->getParameters()[0];

        expect($parameter->getName())->toBe($name)
            ->and($parameter->getDefaultValue())->toBe($label . '-default')
            ->and($parameter->getAttributes(Label::class)[0]->newInstance()->value)->toBe($label);
    }
})->with(['__invoke', '__INVOKE']);
