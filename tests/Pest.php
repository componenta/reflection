<?php

declare(strict_types=1);

use Componenta\Reflection\Reflection;

beforeEach(static function (): void {
    Reflection::clearReflectors();
});
