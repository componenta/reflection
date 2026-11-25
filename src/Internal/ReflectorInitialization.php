<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use LogicException;

/** @internal */
trait ReflectorInitialization
{
    private bool $initialized = false;

    private function initializeOnce(): void
    {
        if ($this->initialized) {
            throw new LogicException('A cached reflector cannot be reinitialized.');
        }
        $this->initialized = true;
    }
}
