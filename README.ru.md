# Componenta Reflection

Утилиты Reflection для PHP 8.4+.

## Установка

```bash
composer require componenta/reflection:^2.0
```

## Reflection

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn (): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::class()` поддерживает классы, интерфейсы, трейты и enum и возвращает `null`, если символ не существует.

`Reflection::callable()` поддерживает функции, closures, конкретные методы и invokable-объекты. Callable, доступные только через `__call()` или `__callStatic()`, отклоняются, поскольку у них нет конкретной сигнатуры метода для Reflection.

`Reflection::reflect()` принимает mixed и возвращает соответствующий native reflector либо `null`, если значение нельзя надёжно отразить.

## Атрибуты

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$attributes = Reflection::getMetadata($class, PermissionPolicy::class);
$first = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$has = Reflection::hasMetadata($class, PermissionPolicy::class);
```

Кешируются определения атрибутов, а их экземпляры создаются заново при каждом чтении. `hasMetadata()` не создаёт экземпляры атрибутов.

Поддерживаются функции, методы, классы, параметры, свойства, константы классов и глобальные константы. Атрибуты глобальных констант доступны на PHP 8.5+.

### Глубокий поиск

```php
$attributes = Reflection::getDeepMetadata($class, SomeAttribute::class);
```

Глубокий поиск включает класс, методы, свойства, property hooks и константы класса. Пути однозначны:

```text
App\Command\CreatePostCommand
App\Command\CreatePostCommand::handle()
App\Command\CreatePostCommand::$title
App\Command\CreatePostCommand::$title::get()
App\Command\CreatePostCommand::STATUS
```

`getFirstDeepMetadata()` возвращает первый найденный атрибут, а `hasDeepMetadata()` проверяет наличие атрибута без создания его экземпляра.

## Reflection типов

```php
use Componenta\Reflection\ReflectionType;

$type = (new ReflectionParameter($callable, 'value'))->getType();

ReflectionType::match($type, $value);
ReflectionType::contains($type, SomeInterface::class);
ReflectionType::getTypeNames($type);
```

`ReflectionType` поддерживает named, union, intersection и DNF-типы.

`getTypeNames(null)` возвращает `[]`. Nullable named-типы нормализуются семантически: `?int` даёт `['int', 'null']`.

Если native Reflection возвращает `self`, `parent` или `static`, передайте declaring/effective class через `scope`:

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

Для relative types, объявленных в trait, в качестве `scope` используется consuming class.

`ReflectionType::toString()` возвращает native строковое представление типа PHP.

### Coercion

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

Coercion ограничен отражёнными PHP-типами. Union преобразуется только когда значение уже соответствует одной ветке или преобразуема ровно одна ветка; неоднозначные преобразования отклоняются.

Array можно получить из array, iterable и `Componenta\Arrayable\Arrayable`. Scalars не оборачиваются в одноэлементный array.

## Управление кешем

```php
Reflection::clearReflectors();
```

Reflectors классов, функций и методов кешируются сильно. Reflectors объектов и closures хранятся в `WeakMap` и не продлевают время жизни объектов приложения.

## Разработка

```bash
composer check
```

Тесты используют Pest 4. Статический анализ выполняется PHPStan на максимальном уровне. CI проверяет PHP 8.4 и PHP 8.5.
