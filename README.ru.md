# Componenta Reflection

Небольшой набор reflection-утилит для библиотек Componenta и приложений на PHP 8.4+.

## Установка

```bash
composer require componenta/reflection
```

## Требования

- PHP 8.4+

## Reflection значений

`Reflection` раздельно кеширует reflectors классов, функций и методов. Reflectors объектов и closures хранятся в `WeakMap`, поэтому кеш не продлевает жизнь объектам приложения в долгоживущих workers.

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn (): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::reflect()` принимает mixed input и возвращает соответствующий native reflector либо `null`, если значение не поддерживается.

`Reflection::class()` возвращает `null`, когда класс нельзя отразить. Ошибки, выброшенные autoloader-ом, не скрываются.

## Чтение attributes

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` возвращает `list<object>|null`. Кешируются definitions attributes, но их экземпляры создаются заново при каждом чтении. `hasMetadata()` и `hasDeepMetadata()` проверяют только definitions и не вызывают constructors attributes.

Поддерживаются functions/methods, classes, parameters, properties и `ReflectionClassConstant`.

## Deep attribute lookup

Deep lookup учитывает class attributes, methods, properties, PHP 8.4 property hooks и class constants.

```php
$attributes = Reflection::getDeepMetadata($class, SomeAttribute::class);

foreach ($attributes as $path => $items) {
    // App\Command\CreatePostCommand
    // App\Command\CreatePostCommand::handle()
    // App\Command\CreatePostCommand::$title
    // App\Command\CreatePostCommand::$title::get()
    // App\Command\CreatePostCommand::STATUS
}
```

У method path присутствуют `()`, поэтому method и class constant с одинаковым именем не могут перезаписать друг друга.

## Reflection типов

`ReflectionType` поддерживает named, union, intersection и DNF types.

```php
use Componenta\Reflection\ReflectionType;

$type = (new ReflectionParameter($callable, 'value'))->getType();

ReflectionType::match($type, $value);
ReflectionType::contains($type, SomeInterface::class);
ReflectionType::getTypeNames($type);
ReflectionType::toString($type);
```

Для `self`, `parent` и `static` передавайте declaring class через `scope`:

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

### Простое PHP-level coercion

`canCoerce()` и `coerce()` намеренно остаются в пакете. Их задача — определить, можно ли привести значение к отражённому **native PHP type**, и выполнить такое преобразование. Это не замена `componenta/caster`: caster отвечает за именованные и доменно-специфичные pipelines преобразования.

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

Контракт строгий: если `canCoerce()` вернул `false`, `coerce()` отклонит то же преобразование. Для union coercion разрешён только уже подходящий branch либо ситуация, когда преобразовать значение можно ровно в один branch; неоднозначные преобразования отклоняются.

В array можно преобразовать array, iterable и `Componenta\Arrayable\Arrayable`. Произвольные scalars больше не оборачиваются молча в одноэлементный array.

## Управление кешем

```php
Reflection::clearReflectors();
```

Метод очищает кеши classes/functions/methods и сбрасывает weak caches объектов, closures и definitions attributes. Основной сценарий — изоляция тестов.

## Разработка

```bash
composer check
```

Тесты написаны на Pest 4. Статический анализ выполняется PHPStan на максимальном уровне. CI проверяет PHP 8.4 и 8.5.
