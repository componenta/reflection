<?php

declare(strict_types=1);

use Componenta\Reflection\Reflection;

require dirname(__DIR__) . '/vendor/autoload.php';

#[Attribute]
final class ReflectionGlobalConstantAttribute
{
    public function __construct(public readonly string $value) {}
}

#[ReflectionGlobalConstantAttribute('global')]
const COMPONENTA_REFLECTION_ATTRIBUTED_CONSTANT = 1;

$reflector = new ReflectionConstant('COMPONENTA_REFLECTION_ATTRIBUTED_CONSTANT');
$metadata = Reflection::getMetadata($reflector, ReflectionGlobalConstantAttribute::class);

if ($metadata === null || count($metadata) !== 1 || $metadata[0]->value !== 'global') {
    throw new RuntimeException('PHP 8.5 global constant metadata reflection failed.');
}
