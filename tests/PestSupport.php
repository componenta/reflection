<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Closure;

function beforeEach(Closure $hook): mixed
{
    return \beforeEach(function () use ($hook): void {
        $hook();
    });
}
