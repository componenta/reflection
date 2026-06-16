<?php

declare(strict_types=1);

namespace Componenta\Reflection\Tests;

use Attribute;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use Componenta\Reflection\Reflection;

final class ReflectionTest extends TestCase
{
    protected function setUp(): void
    {
        Reflection::clearReflectors();
    }

    public function testAddReflectorStoresReflectorInCache(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);
        Reflection::addReflector(PlainClass::class, $reflector);

        $result = Reflection::class(PlainClass::class);

        $this->assertSame($reflector, $result);
    }

    public function testClearReflectorsRemovesAllCachedReflectors(): void
    {
        $first = Reflection::class(PlainClass::class);

        Reflection::clearReflectors();

        $second = Reflection::class(PlainClass::class);

        $this->assertNotSame($first, $second);
    }

    public function testGetMetadataReturnsAllAttributesWhenNoFilterProvided(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $metadata = Reflection::getMetadata($reflector);

        $this->assertIsArray($metadata);
        $this->assertCount(2, $metadata);
        $this->assertInstanceOf(TestAttribute::class, $metadata[0]);
        $this->assertInstanceOf(AnotherAttribute::class, $metadata[1]);
    }

    public function testGetMetadataReturnsFilteredAttributesWhenNameProvided(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $metadata = Reflection::getMetadata($reflector, TestAttribute::class);

        $this->assertIsArray($metadata);
        $this->assertCount(1, $metadata);
        $this->assertInstanceOf(TestAttribute::class, $metadata[0]);
        $this->assertSame('class-level', $metadata[0]->value);
    }

    public function testGetMetadataReturnsNullWhenNoAttributesFound(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $metadata = Reflection::getMetadata($reflector);

        $this->assertNull($metadata);
    }

    public function testGetMetadataReturnsNullWhenFilteredAttributeNotFound(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $metadata = Reflection::getMetadata($reflector, TestAttribute::class);

        $this->assertNull($metadata);
    }

    public function testGetFirstMetadataReturnsFirstMatchingAttribute(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $attribute = Reflection::getFirstMetadata($reflector, TestAttribute::class);

        $this->assertInstanceOf(TestAttribute::class, $attribute);
        $this->assertSame('class-level', $attribute->value);
    }

    public function testGetFirstMetadataReturnsNullWhenAttributeNotFound(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $attribute = Reflection::getFirstMetadata($reflector, TestAttribute::class);

        $this->assertNull($attribute);
    }

    public function testHasMetadataReturnsTrueWhenAttributeExists(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $result = Reflection::hasMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasMetadataReturnsFalseWhenAttributeDoesNotExist(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $result = Reflection::hasMetadata($reflector, TestAttribute::class);

        $this->assertFalse($result);
    }

    public function testReflectReturnsReflectionFunctionForClosure(): void
    {
        $closure = fn() => 'test';

        $result = Reflection::reflect($closure);

        $this->assertInstanceOf(ReflectionFunction::class, $result);
    }

    public function testReflectReturnsReflectionObjectForObject(): void
    {
        $object = new PlainClass();

        $result = Reflection::reflect($object);

        $this->assertInstanceOf(ReflectionObject::class, $result);
    }

    public function testReflectReturnsReflectionClassForClassName(): void
    {
        $result = Reflection::reflect(PlainClass::class);

        $this->assertInstanceOf(ReflectionClass::class, $result);
    }

    public function testReflectReturnsNullForNonExistentClass(): void
    {
        $result = Reflection::reflect('NonExistentClass');

        $this->assertNull($result);
    }

    public function testReflectReturnsNullForUnsupportedType(): void
    {
        $result = Reflection::reflect(123);

        $this->assertNull($result);
    }

    public function testCallableReturnsReflectionFunctionForClosure(): void
    {
        $closure = fn() => null;

        $result = Reflection::callable($closure);

        $this->assertInstanceOf(ReflectionFunction::class, $result);
    }

    public function testCallableCachesClosureReflection(): void
    {
        $closure = fn() => null;

        $first = Reflection::callable($closure);
        $second = Reflection::callable($closure);

        $this->assertSame($first, $second);
    }

    public function testCallableReturnsReflectionFunctionForNamedFunction(): void
    {
        $result = Reflection::callable('array_map');

        $this->assertInstanceOf(ReflectionFunction::class, $result);
        $this->assertSame('array_map', $result->getName());
    }

    public function testCallableReturnsReflectionMethodForStaticMethodString(): void
    {
        $result = Reflection::callable(AnnotatedClass::class . '::staticMethod');

        $this->assertInstanceOf(ReflectionMethod::class, $result);
        $this->assertSame('staticMethod', $result->getName());
    }

    public function testCallableReturnsReflectionMethodForArrayCallable(): void
    {
        $object = new AnnotatedClass();

        $result = Reflection::callable([$object, 'annotatedMethod']);

        $this->assertInstanceOf(ReflectionMethod::class, $result);
        $this->assertSame('annotatedMethod', $result->getName());
    }

    public function testCallableReturnsReflectionMethodForClassArrayCallable(): void
    {
        $result = Reflection::callable([AnnotatedClass::class, 'staticMethod']);

        $this->assertInstanceOf(ReflectionMethod::class, $result);
        $this->assertSame('staticMethod', $result->getName());
    }

    public function testCallableReturnsReflectionMethodForInvokableObject(): void
    {
        $invokable = new InvokableClass();

        $result = Reflection::callable($invokable);

        $this->assertInstanceOf(ReflectionMethod::class, $result);
        $this->assertSame('__invoke', $result->getName());
    }

    public function testObjectReturnsReflectionObject(): void
    {
        $object = new PlainClass();

        $result = Reflection::object($object);

        $this->assertInstanceOf(ReflectionObject::class, $result);
        $this->assertSame(PlainClass::class, $result->getName());
    }

    public function testObjectCachesReflection(): void
    {
        $object = new PlainClass();

        $first = Reflection::object($object);
        $second = Reflection::object($object);

        $this->assertSame($first, $second);
    }

    public function testClassReturnsReflectionClassForExistingClass(): void
    {
        $result = Reflection::class(PlainClass::class);

        $this->assertInstanceOf(ReflectionClass::class, $result);
        $this->assertSame(PlainClass::class, $result->getName());
    }

    public function testClassReturnsNullForNonExistentClass(): void
    {
        $result = Reflection::class('NonExistentClass');

        $this->assertNull($result);
    }

    public function testClassCachesReflection(): void
    {
        $first = Reflection::class(PlainClass::class);
        $second = Reflection::class(PlainClass::class);

        $this->assertSame($first, $second);
    }

    public function testGetDeepMetadataCollectsAttributesFromClassAndMembers(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $attributes = Reflection::getDeepMetadata($reflector, TestAttribute::class);

        $this->assertArrayHasKey(AnnotatedClass::class, $attributes);
        $this->assertArrayHasKey(AnnotatedClass::class . '::annotatedMethod', $attributes);
        $this->assertArrayHasKey(AnnotatedClass::class . '::$name', $attributes);
        $this->assertArrayHasKey(AnnotatedClass::class . '::STATUS', $attributes);
    }

    public function testGetDeepMetadataReturnsCorrectAttributeValues(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $attributes = Reflection::getDeepMetadata($reflector, TestAttribute::class);

        $this->assertSame('class-level', $attributes[AnnotatedClass::class][0]->value);
        $this->assertSame('method-level', $attributes[AnnotatedClass::class . '::annotatedMethod'][0]->value);
        $this->assertSame('property-level', $attributes[AnnotatedClass::class . '::$name'][0]->value);
        $this->assertSame('constant-level', $attributes[AnnotatedClass::class . '::STATUS'][0]->value);
    }

    public function testGetDeepMetadataReturnsEmptyArrayForPlainClass(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $attributes = Reflection::getDeepMetadata($reflector, TestAttribute::class);

        $this->assertEmpty($attributes);
    }

    public function testGetFirstDeepMetadataReturnsFirstFoundAttribute(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $attribute = Reflection::getFirstDeepMetadata($reflector, TestAttribute::class);

        $this->assertInstanceOf(TestAttribute::class, $attribute);
        $this->assertSame('class-level', $attribute->value);
    }

    public function testGetFirstDeepMetadataReturnsNullWhenNoAttributesFound(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $attribute = Reflection::getFirstDeepMetadata($reflector, TestAttribute::class);

        $this->assertNull($attribute);
    }

    public function testHasDeepMetadataReturnsTrueForClassWithAttributes(): void
    {
        $reflector = new ReflectionClass(AnnotatedClass::class);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasDeepMetadataReturnsFalseForClassWithoutAttributes(): void
    {
        $reflector = new ReflectionClass(PlainClass::class);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertFalse($result);
    }

    public function testHasDeepMetadataDetectsAttributeOnMethodOnly(): void
    {
        $reflector = new ReflectionClass(MethodOnlyAnnotated::class);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasDeepMetadataDetectsAttributeOnPropertyOnly(): void
    {
        $reflector = new ReflectionClass(PropertyOnlyAnnotated::class);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasDeepMetadataDetectsAttributeOnConstantOnly(): void
    {
        $reflector = new ReflectionClass(ConstantOnlyAnnotated::class);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testGetMetadataReturnsAttributesFromAnonymousClass(): void
    {
        $anonymous = new #[TestAttribute('anonymous-class')] class {};
        $reflector = new ReflectionObject($anonymous);

        $metadata = Reflection::getMetadata($reflector);

        $this->assertIsArray($metadata);
        $this->assertCount(1, $metadata);
        $this->assertInstanceOf(TestAttribute::class, $metadata[0]);
        $this->assertSame('anonymous-class', $metadata[0]->value);
    }

    public function testGetFirstMetadataReturnsAttributeFromAnonymousClass(): void
    {
        $anonymous = new #[TestAttribute('first-anon')] #[AnotherAttribute(5)] class {};
        $reflector = new ReflectionObject($anonymous);

        $attribute = Reflection::getFirstMetadata($reflector, TestAttribute::class);

        $this->assertInstanceOf(TestAttribute::class, $attribute);
        $this->assertSame('first-anon', $attribute->value);
    }

    public function testHasMetadataReturnsTrueForAnonymousClassWithAttribute(): void
    {
        $anonymous = new #[TestAttribute] class {};
        $reflector = new ReflectionObject($anonymous);

        $result = Reflection::hasMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasMetadataReturnsFalseForAnonymousClassWithoutAttribute(): void
    {
        $anonymous = new class {};
        $reflector = new ReflectionObject($anonymous);

        $result = Reflection::hasMetadata($reflector, TestAttribute::class);

        $this->assertFalse($result);
    }

    public function testGetDeepMetadataCollectsAttributesFromAnonymousClassMembers(): void
    {
        $anonymous = new #[TestAttribute('anon-class')] class {
            #[TestAttribute('anon-const')]
            public const STATUS = 'active';

            #[TestAttribute('anon-prop')]
            public string $name = '';

            #[TestAttribute('anon-method')]
            public function process(): void {}
        };
        $reflector = new ReflectionObject($anonymous);

        $attributes = Reflection::getDeepMetadata($reflector, TestAttribute::class);

        $this->assertNotEmpty($attributes);

        $values = [];
        foreach ($attributes as $path => $attrList) {
            foreach ($attrList as $attr) {
                $values[] = $attr->value;
            }
        }

        $this->assertContains('anon-class', $values);
        $this->assertContains('anon-const', $values);
        $this->assertContains('anon-prop', $values);
        $this->assertContains('anon-method', $values);
    }

    public function testGetFirstDeepMetadataReturnsAttributeFromAnonymousClass(): void
    {
        $anonymous = new #[TestAttribute('deep-first')] class {};
        $reflector = new ReflectionObject($anonymous);

        $attribute = Reflection::getFirstDeepMetadata($reflector, TestAttribute::class);

        $this->assertInstanceOf(TestAttribute::class, $attribute);
        $this->assertSame('deep-first', $attribute->value);
    }

    public function testHasDeepMetadataDetectsAttributeOnAnonymousClassMethod(): void
    {
        $anonymous = new class {
            #[TestAttribute]
            public function annotated(): void {}
        };
        $reflector = new ReflectionObject($anonymous);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasDeepMetadataDetectsAttributeOnAnonymousClassProperty(): void
    {
        $anonymous = new class {
            #[TestAttribute]
            public string $annotated = '';
        };
        $reflector = new ReflectionObject($anonymous);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertTrue($result);
    }

    public function testHasDeepMetadataReturnsFalseForPlainAnonymousClass(): void
    {
        $anonymous = new class {
            public string $prop = '';
            public function method(): void {}
        };
        $reflector = new ReflectionObject($anonymous);

        $result = Reflection::hasDeepMetadata($reflector, TestAttribute::class);

        $this->assertFalse($result);
    }

    public function testReflectReturnsReflectionObjectForAnonymousClassInstance(): void
    {
        $anonymous = new class {};

        $result = Reflection::reflect($anonymous);

        $this->assertInstanceOf(ReflectionObject::class, $result);
    }

    public function testObjectReturnsReflectionObjectForAnonymousClass(): void
    {
        $anonymous = new class {};

        $result = Reflection::object($anonymous);

        $this->assertInstanceOf(ReflectionObject::class, $result);
    }

    public function testObjectCachesAnonymousClassReflection(): void
    {
        $anonymous = new class {};

        $first = Reflection::object($anonymous);
        $second = Reflection::object($anonymous);

        $this->assertSame($first, $second);
    }

    public function testGetMetadataWorksWithAnonymousClassMethodReflection(): void
    {
        $anonymous = new class {
            #[TestAttribute('method-attr')]
            public function annotated(): void {}
        };
        $reflector = new ReflectionObject($anonymous);
        $methodReflector = $reflector->getMethod('annotated');

        $metadata = Reflection::getMetadata($methodReflector, TestAttribute::class);

        $this->assertIsArray($metadata);
        $this->assertCount(1, $metadata);
        $this->assertSame('method-attr', $metadata[0]->value);
    }

    public function testGetDeepMetadataPathsContainAnonymousClassName(): void
    {
        $anonymous = new #[TestAttribute('path-test')] class {
            #[TestAttribute('method-path')]
            public function test(): void {}
        };
        $reflector = new ReflectionObject($anonymous);
        $className = $reflector->getName();

        $attributes = Reflection::getDeepMetadata($reflector, TestAttribute::class);

        $this->assertArrayHasKey($className, $attributes);
        $this->assertArrayHasKey($className . '::test', $attributes);
    }
}

#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
final class TestAttribute
{
    public function __construct(public readonly string $value = 'default') {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final class AnotherAttribute
{
    public function __construct(public readonly int $priority = 0) {}
}

#[TestAttribute('class-level')]
#[AnotherAttribute(priority: 10)]
final class AnnotatedClass
{
    #[TestAttribute('constant-level')]
    public const STATUS = 'active';

    #[TestAttribute('property-level')]
    public string $name = '';

    #[TestAttribute('method-level')]
    public function annotatedMethod(): void {}

    public function plainMethod(): void {}

    public static function staticMethod(): void {}
}

final class PlainClass
{
    public string $value = '';

    public function doSomething(): void {}
}

final class InvokableClass
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}

final class MethodOnlyAnnotated
{
    #[TestAttribute]
    public function method(): void {}
}

final class PropertyOnlyAnnotated
{
    #[TestAttribute]
    public string $prop = '';
}

final class ConstantOnlyAnnotated
{
    #[TestAttribute]
    public const VALUE = 1;
}
