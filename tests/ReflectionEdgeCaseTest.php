<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Attribute;
use Closure;
use Componenta\Reflection\Reflection;
use ErrorException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionConstant;
use ReflectionMethod;
use ReflectionObject;

#[Attribute]
final class EdgeAttribute {}

#[EdgeAttribute]
final class EdgeAnnotatedClass {}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
final class RetargetingMetadata
{
    /** @var ReflectionClass<object>|null */
    public static ?ReflectionClass $reflector = null;

    public function __construct(public string $value, bool $retarget = false)
    {
        if ($retarget) {
            self::$reflector?->__construct(RetargetedDeclaration::class);
        }
    }
}

#[RetargetingMetadata('original class', retarget: true)]
final class OriginalDeclaration
{
    #[RetargetingMetadata('original method')]
    public function original(): void {}
}

final class RetargetedDeclaration
{
    #[RetargetingMetadata('replacement method')]
    public function replacement(): void {}
}

/** @extends ReflectionClass<object> */
final class AttributeReadRetargetingClass extends ReflectionClass
{
    private bool $firstRead = true;

    /**
     * @param class-string $class
     * @param class-string $replacement
     */
    public function __construct(string $class, private readonly string $replacement, private readonly bool $beforeRead)
    {
        parent::__construct($class);
    }

    /** @return list<\ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        if (!$this->firstRead) {
            return parent::getAttributes($name, $flags);
        }
        $this->firstRead = false;
        $definitions = $this->beforeRead ? [] : parent::getAttributes($name, $flags);
        parent::__construct($this->replacement);
        return $this->beforeRead ? parent::getAttributes($name, $flags) : $definitions;
    }
}

final class MemberMetadataSource
{
    #[RetargetingMetadata('original property')]
    public string $original;
}

final class MemberMetadataReplacement
{
    #[RetargetingMetadata('replacement property')]
    public string $replacement;

    #[RetargetingMetadata('replacement method')]
    public function replace(): void {}
}

/** @extends ReflectionClass<object> */
final class MemberReadRetargetingClass extends ReflectionClass
{
    private bool $firstRead = true;

    /** @param class-string $class */
    public function __construct(string $class, private readonly bool $beforeRead)
    {
        parent::__construct($class);
    }

    /** @return list<ReflectionMethod> */
    public function getMethods(?int $filter = null): array
    {
        if (!$this->firstRead) {
            return parent::getMethods($filter);
        }
        $this->firstRead = false;
        $methods = $this->beforeRead ? [] : parent::getMethods($filter);
        parent::__construct(MemberMetadataReplacement::class);
        return $this->beforeRead ? parent::getMethods($filter) : $methods;
    }
}

interface EdgeInterface {}
trait EdgeTrait {}
enum EdgeEnum { case Value; }

final class EdgeMagicCallable
{
    public function privateClosure(): Closure
    {
        return $this->hidden(...);
    }

    private function hidden(string $value = 'declared'): string
    {
        return $value;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return null;
    }
}

class EdgeCallableAncestor
{
    public function select(string $value = 'ancestor'): string
    {
        return $value;
    }

    public static function selectStatic(string $value = 'ancestor static'): string
    {
        return $value;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return 'magic';
    }
}

final class EdgeCallableChild extends EdgeCallableAncestor
{
    public function select(string $value = 'child'): string
    {
        return $value;
    }
}

class QualifiedMagicAncestor
{
    /** @param array<array-key, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): string
    {
        return 'magic:' . $method;
    }
}

final class QualifiedMagicChild extends QualifiedMagicAncestor
{
    public static function selected(string $value = 'declared'): string
    {
        return $value;
    }
}

test('a string callable with a separator inside its class alias reflects the concrete method', function (): void {
    $alias = EdgeCallableAncestor::class . '::Alias';
    class_alias(EdgeCallableAncestor::class, $alias);
    $callable = $alias . '::selectStatic';
    if (!is_callable($callable)) {
        throw new \LogicException('Expected a native static callable.');
    }

    $method = Reflection::callable($callable);

    expect($method->getParameters()[0]->getDefaultValue())->toBe('ancestor static')
        ->and(Reflection::reflect($callable))->toBe($method)
        ->and(Reflection::callable([$alias, 'selectStatic']))->toBe($method);
});

test('qualified callables reflect the declaration selected by PHP', function (): void {
    $target = new EdgeCallableChild();
    $ancestor = [$target, EdgeCallableAncestor::class . '::select'];
    $static = [EdgeCallableChild::class, EdgeCallableAncestor::class . '::selectStatic'];
    $magic = [$target, EdgeCallableAncestor::class . '::missing'];
    $closure = static fn (string $value = 'closure'): string => $value;
    $qualifiedClosure = [$closure, Closure::class . '::__invoke'];
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        if ($level === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        foreach ([[$ancestor, 'ancestor'], [$static, 'ancestor static'], [$qualifiedClosure, 'closure']] as [$input, $default]) {
            if (!is_callable($input)) {
                throw new \LogicException('Expected a native qualified callable.');
            }
            expect(Closure::fromCallable($input)())->toBe($default)
                ->and(Reflection::callable($input)->getParameters()[0]->getDefaultValue())->toBe($default);
        }
        expect(Reflection::callable([$target, 'select'])->getParameters()[0]->getDefaultValue())->toBe('child')
            ->and(Reflection::reflect($magic))->toBeNull()
            ->and(Reflection::reflect($qualifiedClosure))->toBe(Reflection::callable($closure));
    } finally {
        restore_error_handler();
    }
});

test('class reflection reuses an instance published by its own autoloader', function (): void {
    $target = __NAMESPACE__ . '\\ReflectionDefinedDuringLookup';
    $inside = null;
    $autoload = static function (string $name) use ($target, &$inside): void {
        if ($name === $target) {
            class_alias(EdgeAnnotatedClass::class, $target);
            $inside = Reflection::class($target);
        }
    };
    spl_autoload_register($autoload);
    try {
        $outside = Reflection::class($target);
    } finally {
        spl_autoload_unregister($autoload);
    }

    expect($outside?->getName())->toBe(EdgeAnnotatedClass::class)
        ->and($outside)->toBe($inside)
        ->and(Reflection::class(strtolower($target)))->toBe($outside);
});

test('metadata cache separates unfiltered and empty-name lookups', function (): void {
    $reflector = new ReflectionClass(EdgeAnnotatedClass::class);

    expect(Reflection::getMetadata($reflector))->toHaveCount(1)
        ->and(Reflection::getMetadata($reflector, ''))->toBeNull();

    Reflection::clearReflectors();
    $reflector = new ReflectionClass(EdgeAnnotatedClass::class);

    expect(Reflection::getMetadata($reflector, ''))->toBeNull()
        ->and(Reflection::getMetadata($reflector))->toHaveCount(1);
});

test('deep metadata uses one declaration if an attribute retargets the reflector', function (): void {
    $reflector = new ReflectionClass(OriginalDeclaration::class);
    RetargetingMetadata::$reflector = $reflector;

    try {
        $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);
    } finally {
        RetargetingMetadata::$reflector = null;
    }
    $next = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_map(static fn (array $attributes): string => $attributes[0]->value, $metadata))->toBe([
        OriginalDeclaration::class => 'original class',
        OriginalDeclaration::class . '::original()' => 'original method',
    ])->and(array_map(static fn (array $attributes): string => $attributes[0]->value, $next))->toBe([
        RetargetedDeclaration::class . '::replacement()' => 'replacement method',
    ]);
});

test('deep metadata retains the selected class when reading its attributes retargets the reflector', function (bool $beforeRead): void {
    $reflector = new AttributeReadRetargetingClass(OriginalDeclaration::class, RetargetedDeclaration::class, $beforeRead);
    $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);
    $next = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_map(static fn (array $attributes): string => $attributes[0]->value, $metadata))->toBe([
        OriginalDeclaration::class => 'original class',
        OriginalDeclaration::class . '::original()' => 'original method',
    ])->and(array_map(static fn (array $attributes): string => $attributes[0]->value, $next))->toBe([
        RetargetedDeclaration::class . '::replacement()' => 'replacement method',
    ]);
})->with(['before reading attributes' => true, 'after reading attributes' => false]);

test('short deep metadata queries use the selected class through an attribute read', function (bool $beforeRead): void {
    $first = new AttributeReadRetargetingClass(RetargetedDeclaration::class, OriginalDeclaration::class, $beforeRead);
    $has = new AttributeReadRetargetingClass(EdgeAnnotatedClass::class, RetargetedDeclaration::class, $beforeRead);

    expect(Reflection::getFirstDeepMetadata($first, RetargetingMetadata::class)?->value)->toBe('replacement method')
        ->and(Reflection::hasDeepMetadata($has, RetargetingMetadata::class))->toBeFalse()
        ->and(Reflection::getFirstDeepMetadata($first, RetargetingMetadata::class)?->value)->toBe('original class')
        ->and(Reflection::hasDeepMetadata($has, RetargetingMetadata::class))->toBeTrue();
})->with(['before reading attributes' => true, 'after reading attributes' => false]);

test('short deep metadata queries keep custom class metadata and skip member lookup on a match', function (): void {
    $reflector = new class (RetargetedDeclaration::class) extends ReflectionClass {
        public function getAttributes(?string $name = null, int $flags = 0): array
        {
            return new ReflectionClass(OriginalDeclaration::class)->getAttributes($name, $flags);
        }

        public function getMethods(?int $filter = null): array
        {
            throw new \LogicException('A class match completes this lookup.');
        }
    };

    expect(Reflection::getFirstDeepMetadata($reflector, RetargetingMetadata::class)?->value)->toBe('original class')
        ->and(Reflection::hasDeepMetadata($reflector, RetargetingMetadata::class))->toBeTrue();
});

test('deep metadata keeps the selected class when a member query retargets its reflector', function (bool $beforeRead): void {
    $reflector = new MemberReadRetargetingClass(MemberMetadataSource::class, $beforeRead);
    $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);
    $next = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_map(static fn (array $attributes): string => $attributes[0]->value, $metadata))->toBe([
        MemberMetadataSource::class . '::$original' => 'original property',
    ])->and(array_map(static fn (array $attributes): string => $attributes[0]->value, $next))->toBe([
        MemberMetadataReplacement::class . '::replace()' => 'replacement method',
        MemberMetadataReplacement::class . '::$replacement' => 'replacement property',
    ]);
})->with(['before reading methods' => true, 'after reading methods' => false]);

test('short deep queries do not inspect replacement members during the current lookup', function (bool $beforeRead): void {
    $first = new MemberReadRetargetingClass(MemberMetadataSource::class, $beforeRead);
    $has = new MemberReadRetargetingClass(EdgeAnnotatedClass::class, $beforeRead);

    expect(Reflection::getFirstDeepMetadata($first, RetargetingMetadata::class)?->value)->toBe('original property')
        ->and(Reflection::hasDeepMetadata($has, RetargetingMetadata::class))->toBeFalse()
        ->and(Reflection::getFirstDeepMetadata($first, RetargetingMetadata::class)?->value)->toBe('replacement method')
        ->and(Reflection::hasDeepMetadata($has, RetargetingMetadata::class))->toBeTrue();
})->with(['before reading methods' => true, 'after reading methods' => false]);

test('deep queries preserve custom member filters and stop on a matching method', function (): void {
    $filtered = new class (MemberMetadataReplacement::class) extends ReflectionClass {
        public function getMethods(?int $filter = null): array
        {
            return [];
        }
    };
    $methodOnly = new class (MemberMetadataReplacement::class) extends ReflectionClass {
        public function getProperties(?int $filter = null): array
        {
            throw new \LogicException('The matching method completes the lookup.');
        }
    };

    expect(array_keys(Reflection::getDeepMetadata($filtered, RetargetingMetadata::class)))
        ->toBe([MemberMetadataReplacement::class . '::$replacement'])
        ->and(Reflection::getFirstDeepMetadata($methodOnly, RetargetingMetadata::class)?->value)->toBe('replacement method')
        ->and(Reflection::hasDeepMetadata($methodOnly, RetargetingMetadata::class))->toBeTrue();
});

test('deep lookup uses the initial custom path and native declaration identity', function (): void {
    /** @var class-string $alias */
    $alias = __NAMESPACE__ . '\\MemberMetadataAlias';
    class_alias(MemberMetadataSource::class, $alias);
    $reflector = new class ($alias) extends ReflectionClass {
        public int $nameReads = 0;

        public string $name {
            get => throw new \LogicException('Native identity must bypass the property hook for ' . $this->name . '.');
        }

        /** @param class-string $alias */
        public function __construct(private readonly string $alias)
        {
            parent::__construct(MemberMetadataSource::class);
        }

        public function getName(): string
        {
            ++$this->nameReads;
            return $this->alias;
        }

        public function getMethods(?int $filter = null): array
        {
            parent::__construct(MemberMetadataReplacement::class);
            return parent::getMethods($filter);
        }
    };

    $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_keys($metadata))->toBe([$alias . '::$original'])
        ->and($reflector->nameReads)->toBe(1);
});

test('deep lookup keeps the native owner when the initial name reader retargets the reflector', function (): void {
    $reflector = new class (MemberMetadataSource::class) extends ReflectionClass {
        public function getName(): string
        {
            $name = parent::getName();
            parent::__construct(MemberMetadataReplacement::class);
            return $name;
        }
    };

    $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_keys($metadata))->toBe([MemberMetadataSource::class . '::$original']);
});

test('deep lookup holds its declaration through native lazy property reflection', function (): void {
    $class = new ReflectionClass(MemberMetadataSource::class);
    $reflector = new ReflectionObject(new MemberMetadataSource());
    $lazy = $class->newLazyGhost(static function (MemberMetadataSource $object) use ($reflector): void {
        $reflector->__construct(new MemberMetadataReplacement());
        $object->original = 'initialized';
    });
    $reflector->__construct($lazy);

    $metadata = Reflection::getDeepMetadata($reflector, RetargetingMetadata::class);

    expect(array_keys($metadata))->toBe([MemberMetadataSource::class . '::$original'])
        ->and($class->isUninitializedLazyObject($lazy))->toBeFalse()
        ->and($reflector->getName())->toBe(MemberMetadataReplacement::class);
});

test('ReflectionConstant metadata is safe across supported PHP versions', function (): void {
    $name = __NAMESPACE__ . '\\EDGE_UNANNOTATED_CONSTANT';
    if (!defined($name)) {
        define($name, 1);
    }

    expect(Reflection::getMetadata(new ReflectionConstant($name)))->toBeNull();
});

test('class reflection supports all class-like symbols', function (): void {
    expect(Reflection::class(EdgeAnnotatedClass::class)?->getName())->toBe(EdgeAnnotatedClass::class)
        ->and(Reflection::class(EdgeInterface::class)?->getName())->toBe(EdgeInterface::class)
        ->and(Reflection::class(EdgeTrait::class)?->getName())->toBe(EdgeTrait::class)
        ->and(Reflection::class(EdgeEnum::class)?->getName())->toBe(EdgeEnum::class);
});

test('missing class-like symbols invoke the autoloader once', function (): void {
    $target = __NAMESPACE__ . '\\DefinitelyMissingEdgeSymbol';
    $calls = 0;
    $loader = static function (string $class) use ($target, &$calls): void {
        if ($class === $target) {
            ++$calls;
        }
    };

    spl_autoload_register($loader, prepend: true);
    try {
        expect(Reflection::class($target))->toBeNull()
            ->and($calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('magic-dispatch callables have no concrete method reflection', function (): void {
    $object = new EdgeMagicCallable();
    $instanceCallable = [$object, 'missing'];
    $staticCallable = EdgeMagicCallable::class . '::missing';

    expect(is_callable($instanceCallable))->toBeTrue()
        ->and(is_callable($staticCallable))->toBeTrue();

    expect(fn () => Reflection::callable($instanceCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');
    expect(fn () => Reflection::callable($staticCallable))
        ->toThrow(InvalidArgumentException::class, 'magic dispatch');

    expect(Reflection::reflect($instanceCallable))->toBeNull()
        ->and(Reflection::reflect($staticCallable))->toBeNull();
});

test('captured magic dispatch has no concrete signature', function (): void {
    $object = new EdgeMagicCallable();
    $calls = [
        Closure::fromCallable([$object, 'missing']),
        Closure::fromCallable([EdgeMagicCallable::class, 'missing']),
        Closure::fromCallable([$object, 'hidden']),
    ];
    foreach ($calls as $callable) {
        expect(fn () => Reflection::callable($callable))
            ->toThrow(InvalidArgumentException::class, 'magic dispatch')
            ->and(Reflection::reflect($callable))->toBeNull();
    }

    expect(Reflection::callable($object->privateClosure())->getParameters()[0]->getDefaultValue())->toBe('declared')
        ->and(Reflection::callable(strlen(...))->getNumberOfParameters())->toBe(1)
        ->and(Reflection::callable((new \ArrayObject())->count(...))->getNumberOfParameters())->toBe(0);
});

test('qualified captures use the selected declaration when detecting magic dispatch', function (): void {
    $child = new QualifiedMagicChild();
    $qualified = [$child, QualifiedMagicAncestor::class . '::selected'];
    $native = new ReflectionClass(QualifiedMagicChild::class);
    $nativeQualified = [$native, ReflectionClass::class . '::__toString'];
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        if ($level === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        $magic = Closure::fromCallable($qualified);
        $nativeCapture = Closure::fromCallable($nativeQualified);

        expect($magic())->toBe('magic:selected')
            ->and(Reflection::reflect($magic))->toBeNull()
            ->and(fn () => Reflection::callable($magic))->toThrow(InvalidArgumentException::class, 'magic dispatch')
            ->and(Reflection::callable(QualifiedMagicChild::selected(...))->invoke())->toBe('declared')
            ->and(Reflection::callable($nativeCapture)->invoke())->toBe((string) $native);
    } finally {
        restore_error_handler();
    }
});
