<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use Closure;
use ReflectionFunction;

/** @internal */
final class CachedFunctionReflection extends ReflectionFunction
{
    use ReflectorInitialization;

    public function __construct(Closure|string $function)
    {
        $this->initializeOnce();
        parent::__construct($function);
    }
}
