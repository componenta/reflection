<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Attribute;
use Componenta\Reflection\Reflection;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;
use WeakReference;

beforeEach(function (): void {
    CountingAttribute::$constructed = 0;
});

test('reads and filters metadata through the public API', function (): void {
    $reflector = new ReflectionClass(AnnotatedClass::class);

    $all = Reflection::getMetadata($reflector);
    $filtered = Reflection::getMetadata($reflector, TestAttribute::class);

    expect($all)
        ->toHaveCount(2)
        ->and($all[0])->toBeInstanceOf(TestAttribute::class)
        ->and($all[1])->toBeInstanceOf(AnotherAttribute::class)
        ->and($filtered)->toHaveCount(1)
        ->and($filtered[0]->value)->toBe('class-level');
});

test('returns null when metadata is absent', function (): void {
    $reflector = new ReflectionClass(PlainClass::class);

    expect(Reflection::getMetadata($reflector, TestAttribute::class))->toBeNull()
        ->and(Reflection::getFirstMetadata($reflector, TestAttribute::class))->toBeNull()
        ->and(Reflection::hasMetadata($reflector, TestAttribute::class))->toBeFalse();
});

test('hasMetadata does not instantiate attributes', function (): void {
    $reflector = new ReflectionClass(CountingAnnotatedClass::class);

    expect(Reflection::hasMetadata($reflector, CountingAttribute::class))->toBeTrue()
        ->and(CountingAttribute::$constructed)->toBe(0);

    expect(Reflection::getFirstMetadata($reflector, CountingAttribute::class))
        ->toBeInstanceOf(CountingAttribute::class)
        ->and(CountingAttribute::$constructed)->toBe(1);
});

test('metadata calls return fresh attribute instances', function (): void {
    $reflector = new ReflectionClass(AnnotatedClass::class);

    $first = Reflection::getFirstMetadata($reflector, TestAttribute::class);
    $first->value = 'mutated';
    $second = Reflection::getFirstMetadata($reflector, TestAttribute::class);

    expect($second)
        ->not->toBe($first)
        ->and($second->value)->toBe('class-level');
});

test('supports ReflectionClassConstant metadata directly', function (): void {
    $constant = (new ReflectionClass(AnnotatedClass::class))->getReflectionConstant('STATUS');

    expect($constant)->not->toBeFalse();

    $metadata = Reflection::getMetadata($constant, TestAttribute::class);

    expect($metadata)
        ->toHaveCount(1)
        ->and($metadata[0]->value)->toBe('constant-level');
});

test('reflect chooses the appropriate native reflector', function (): void {
    $closure = static fn (): string => 'ok';
    $object = new PlainClass();

    expect(Reflection::reflect($closure))->toBeInstanceOf(ReflectionFunction::class)
        ->and(Reflection::reflect($object))->toBeInstanceOf(ReflectionObject::class)
        ->and(Reflection::reflect(PlainClass::class))->toBeInstanceOf(ReflectionClass::class)
        ->and(Reflection::reflect(123))->toBeNull();
});

test('caches class function method closure and object reflectors independently', function (): void {
    $closure = static function (): void {};
    $object = new AnnotatedClass();

    expect(Reflection::class(AnnotatedClass::class))->toBe(Reflection::class(AnnotatedClass::class))
        ->and(Reflection::callable(__NAMESPACE__ . '\\sharedFunction'))->toBe(Reflection::callable(__NAMESPACE__ . '\\sharedFunction'))
        ->and(Reflection::callable([AnnotatedClass::class, 'staticMethod']))->toBe(Reflection::callable([AnnotatedClass::class, 'staticMethod']))
        ->and(Reflection::callable($closure))->toBe(Reflection::callable($closure))
        ->and(Reflection::object($object))->toBe(Reflection::object($object));
});

test('closure callable and object reflection cannot poison each other', function (): void {
    $closure = static function (): void {};

    $objectReflection = Reflection::object($closure);
    $callableReflection = Reflection::callable($closure);

    expect($objectReflection)->toBeInstanceOf(ReflectionObject::class)
        ->and($callableReflection)->toBeInstanceOf(ReflectionFunction::class)
        ->and(Reflection::object($closure))->toBe($objectReflection)
        ->and(Reflection::callable($closure))->toBe($callableReflection);
});

test('a class and function with the same FQN use separate caches', function (): void {
    $classReflection = Reflection::class(SharedSymbol::class);
    $functionReflection = Reflection::callable(__NAMESPACE__ . '\\SharedSymbol');

    expect($classReflection)->toBeInstanceOf(ReflectionClass::class)
        ->and($functionReflection)->toBeInstanceOf(ReflectionFunction::class)
        ->and($classReflection->getName())->toBe(SharedSymbol::class)
        ->and($functionReflection->getName())->toBe(__NAMESPACE__ . '\\SharedSymbol');
});

test('invokable callable reflection does not collide with class reflection', function (): void {
    $invokable = new InvokableClass();

    expect(Reflection::callable($invokable))->toBeInstanceOf(ReflectionMethod::class)
        ->and(Reflection::class(InvokableClass::class))->toBeInstanceOf(ReflectionClass::class);
});

test('object and closure caches do not keep user objects alive', function (): void {
    $object = new PlainClass();
    $closure = static function (): void {};
    $objectReference = WeakReference::create($object);
    $closureReference = WeakReference::create($closure);

    Reflection::object($object);
    Reflection::callable($closure);

    unset($object, $closure);
    gc_collect_cycles();

    expect($objectReference->get())->toBeNull()
        ->and($closureReference->get())->toBeNull();
});

test('missing classes return null but autoloader failures propagate', function (): void {
    expect(Reflection::class(__NAMESPACE__ . '\\DefinitelyMissingClass'))->toBeNull();

    $class = __NAMESPACE__ . '\\BrokenAutoloadClass';
    $loader = static function (string $candidate) use ($class): void {
        if ($candidate === $class) {
            throw new RuntimeException('autoload exploded');
        }
    };

    spl_autoload_register($loader);
    try {
        expect(static fn () => Reflection::class($class))
            ->toThrow(RuntimeException::class, 'autoload exploded');
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('clearReflectors drops strong caches', function (): void {
    $first = Reflection::class(PlainClass::class);

    Reflection::clearReflectors();

    expect(Reflection::class(PlainClass::class))->not->toBe($first);
});

test('deep metadata uses unambiguous paths and includes property hooks', function (): void {
    $metadata = Reflection::getDeepMetadata(
        new ReflectionClass(AnnotatedClass::class),
        TestAttribute::class,
    );

    expect($metadata)
        ->toHaveKey(AnnotatedClass::class)
        ->toHaveKey(AnnotatedClass::class . '::annotatedMethod()')
        ->toHaveKey(AnnotatedClass::class . '::$name')
        ->toHaveKey(AnnotatedClass::class . '::$hooked::get()')
        ->toHaveKey(AnnotatedClass::class . '::$hooked::set()')
        ->toHaveKey(AnnotatedClass::class . '::STATUS')
        ->and($metadata[AnnotatedClass::class . '::$hooked::get()'][0]->value)->toBe('hook-get')
        ->and($metadata[AnnotatedClass::class . '::$hooked::set()'][0]->value)->toBe('hook-set');
});

test('method and constant metadata with the same name do not overwrite each other', function (): void {
    $metadata = Reflection::getDeepMetadata(
        new ReflectionClass(CollisionAnnotatedClass::class),
        TestAttribute::class,
    );

    expect($metadata[CollisionAnnotatedClass::class . '::STATUS()'][0]->value)->toBe('method')
        ->and($metadata[CollisionAnnotatedClass::class . '::STATUS'][0]->value)->toBe('constant');
});

test('deep existence checks do not instantiate attributes', function (): void {
    $reflector = new ReflectionClass(DeepCountingAnnotatedClass::class);

    expect(Reflection::hasDeepMetadata($reflector, CountingAttribute::class))->toBeTrue()
        ->and(CountingAttribute::$constructed)->toBe(0);
});

test('first deep metadata stops after the first matching attribute', function (): void {
    $attribute = Reflection::getFirstDeepMetadata(
        new ReflectionClass(AnnotatedClass::class),
        TestAttribute::class,
    );

    expect($attribute)
        ->toBeInstanceOf(TestAttribute::class)
        ->and($attribute->value)->toBe('class-level');
});

function sharedFunction(): void {}
function SharedSymbol(): void {}

#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
final class TestAttribute
{
    public function __construct(public string $value = 'default') {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final class AnotherAttribute
{
    public function __construct(public readonly int $priority = 0) {}
}

#[Attribute(Attribute::TARGET_ALL)]
final class CountingAttribute
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }
}

#[TestAttribute('class-level')]
#[AnotherAttribute(priority: 10)]
final class AnnotatedClass
{
    #[TestAttribute('constant-level')]
    public const STATUS = 'active';

    #[TestAttribute('property-level')]
    public string $name = '';

    public string $hooked = '' {
        #[TestAttribute('hook-get')]
        get => $this->hooked;

        #[TestAttribute('hook-set')]
        set => $value;
    }

    #[TestAttribute('method-level')]
    public function annotatedMethod(): void {}

    public static function staticMethod(): void {}
}

final class PlainClass {}

final class InvokableClass
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}

final class SharedSymbol {}

#[CountingAttribute]
final class CountingAnnotatedClass {}

final class DeepCountingAnnotatedClass
{
    #[CountingAttribute]
    public function annotated(): void {}
}

final class CollisionAnnotatedClass
{
    #[TestAttribute('constant')]
    public const STATUS = 'active';

    #[TestAttribute('method')]
    public function STATUS(): void {}
}
