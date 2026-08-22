# Asset Assignment Bundle

Бандл для автоматического назначения ресурсов (Assets) к объектам данных (DataObjects) в Pimcore на основе полей соответствия.

## Описание

Asset Assignment Bundle сканирует исходную папку, группирует файлы по базовому коду из имени файла, находит соответствующие DataObject-ы по полю соответствия, перемещает файлы в целевую папку и при необходимости прикрепляет их к объектам.

## Основные возможности

- Пакетное перемещение файлов с пагинацией (батчи по 200 файлов, чтобы не грузить память)
- Массовый поиск DataObject-ов через один `IN(...)` запрос вместо N отдельных
- Два встроенных способа перемещения:
  - `create_folder_and_move` — создаёт подпапку по значению поля соответствия
  - `move_to_target` — кладёт файл прямо в целевую папку
- Прикрепление файлов к полям `image`, `imageGallery`, `hotspotimage`, `manyToManyRelation`
- Защита от конкурентного запуска через распределённый Redis-лок (30 мин TTL)
- Защита от бесконечного цикла: источник и цель не могут совпадать или вкладываться
- Асинхронное выполнение через Symfony Messenger (транспорт `asset_assignment`)
- Прогресс в реальном времени через Redis Cache
- События `AssetAssignmentCompletedEvent` / `AssetAssignmentCancelledEvent` для интеграции с приложением
- Консольная команда и веб-интерфейс через DataHub
- Логи неприкреплённых файлов (`warning`), чтобы мониторить накопление

## Требования

| Компонент | Версия |
|-----------|--------|
| PHP | ≥ 8.1 |
| open-dxp/opendxp | ^1.0 |
| Symfony Framework | ^7.4 |
| symfony/lock | ^7.4 |
| symfony/cache | ^7.4 |

Env-переменные: `REDIS_DSN` (обязательная — используется для прогресса и блокировок).

## Структура

```
asset-assignment-bundle/
├── composer.json
├── config/
│   ├── pimcore/routing.yml
│   └── services.yaml
├── public/
│   ├── css/assetAssignment.css
│   ├── img/assetAssignment.svg
│   └── js/pimcore/
│       ├── adapter/assetAssignment.js
│       └── configuration/
│           ├── configItem.js
│           └── execution.js
├── src/
│   ├── AssetAssignmentBundle.php
│   ├── Command/
│   │   └── ExecuteAssignmentCommand.php
│   ├── Controller/
│   │   └── AssetAssignmentController.php
│   ├── DependencyInjection/
│   │   ├── AssetAssignmentExtension.php
│   │   └── Configuration.php
│   ├── Dto/
│   │   ├── ConfigurationDto.php          # Валидированная конфигурация одного профиля
│   │   ├── ExecutionProgress.php         # Снапшот прогресса из Redis
│   │   ├── ExecutionStatistics.php       # Счётчики текущего запуска
│   │   ├── FileGroupItem.php
│   │   └── ModifiedObjectInfo.php
│   ├── Event/
│   │   ├── AssetAssignmentCancelledEvent.php
│   │   └── AssetAssignmentCompletedEvent.php
│   ├── Exception/
│   │   ├── AssetAssignmentException.php
│   │   ├── AttachmentException.php
│   │   ├── ConfigurationNotFoundException.php
│   │   ├── FolderNotFoundException.php
│   │   ├── InfiniteLoopException.php
│   │   ├── InvalidConfigurationException.php
│   │   └── InvalidFieldTypeException.php
│   ├── Message/
│   │   └── AssetAssignmentMessage.php
│   ├── Messenger/Handler/
│   │   └── AssetAssignmentHandler.php
│   ├── Service/
│   │   ├── AssetAssignmentService.php         # Основная оркестрация
│   │   ├── AssetAssignmentServiceInterface.php
│   │   ├── AttachmentHandler.php              # Прикрепление файлов к полям
│   │   ├── ConfigurationLoader.php            # Загрузка и валидация конфига + пагинация файлов
│   │   ├── DataObjectFinder.php               # Поиск DataObject-ов (single + batch)
│   │   ├── ExecutionLockService.php           # Распределённый Redis-лок
│   │   └── FileGrouper.php                    # Группировка файлов по базовому коду
│   ├── Strategy/
│   │   ├── CreateFolderAndMoveStrategy.php
│   │   ├── FileMovementStrategyFactory.php
│   │   ├── FileMovementStrategyInterface.php
│   │   └── MoveToTargetStrategy.php
│   └── Validator/
│       └── ConfigurationValidator.php         # Проверка конфига + защита от инф. цикла
└── translations/
    ├── admin.en.yml
    └── admin.ru.yml
```

## Установка

1. `composer require iperson1337/opendxp-asset-assignment-bundle`
2. Зарегистрируйте в `config/bundles.php`:
   ```php
   Iperson1337\AssetAssignmentBundle\AssetAssignmentBundle::class => ['all' => true],
   ```
3. Убедитесь, что в `.env` задан `REDIS_DSN`
4. Очистите кэш и установите ассеты:
   ```bash
   php bin/console cache:clear
   php bin/console assets:install
   ```
5. Настройте транспорт `asset_assignment` в `config/packages/messenger.yaml`:
   ```yaml
   framework:
       messenger:
           transports:
               asset_assignment:
                   dsn: '%env(REDIS_DSN)%'
                   options:
                       stream: asset_assignment
           routing:
               'Iperson1337\AssetAssignmentBundle\Message\AssetAssignmentMessage': asset_assignment
   ```

## Конфигурация профиля

Параметры задаются через DataHub (тип "Asset Assignment") или напрямую в YAML.

| Параметр | Обязательный | Описание |
|---------|:---:|---------|
| `source_folder` | ✓ | Исходная папка в дереве ассетов |
| `target_folder` | ✓ | Целевая папка для перемещения |
| `data_object_class_id` | ✓ | ID или имя класса DataObject |
| `match_field` | ✓ | Поле DataObject для сопоставления с именем файла |
| `strategy` | — | `create_folder_and_move` (по умолчанию) или `move_to_target` |
| `attachment_field` | — | Поле типа image/imageGallery/... для прикрепления |
| `clear_gallery_before_assignment` | — | Очищать ли галерею перед прикреплением |
| `batch_size` | — | Размер батча (1–1000, по умолчанию 200) |

### Стратегии перемещения

**`create_folder_and_move`**
Создаёт подпапку `<target_folder>/<match_field_value>/` и перемещает файл туда.
Папки кэшируются в памяти в рамках одного запуска — не создаются дважды.

**`move_to_target`**
Перемещает файл прямо в `<target_folder>/`.

## Использование

### Веб-интерфейс

1. DataHub → конфигурация Asset Assignment → вкладка "Выполнение"
2. Нажмите "Запустить" — выполнение уходит в фон
3. Прогресс обновляется в реальном времени
4. "Отменить" завершает трекинг и публикует `AssetAssignmentCancelledEvent`

### Консольная команда

```bash
# Запустить один или несколько профилей
php bin/console asset-assignment:execute MainImageAssigner
php bin/console asset-assignment:execute ProfileA ProfileB

# Verbose — выводит шаги инициализации
php bin/console asset-assignment:execute MainImageAssigner -v
```

> `--dry-run` флаг объявлен, но пока не реализован — команда завершится с предупреждением.

## API Endpoints

| Метод | Путь | Описание |
|-------|------|---------|
| `POST` | `/admin/asset-assignment/start-execution` | Запустить выполнение |
| `GET` | `/admin/asset-assignment/check-execution-progress` | Прогресс текущего запуска |
| `PUT` | `/admin/asset-assignment/cancel-execution` | Отменить выполнение |
| `GET` | `/admin/asset-assignment/load-media-fields` | Медиа-поля класса для UI |

Все POST/PUT принимают `config_name` в теле запроса, GET — в query string.

## Поток выполнения

```
Пользователь (UI / консоль)
        │
        ▼
AssetAssignmentController::startExecutionAction()
  - isRunning? → 409
  - initializeExecution() → пишет общее кол-во файлов в Redis
  - dispatch(AssetAssignmentMessage) → транспорт asset_assignment
        │
        ▼
AssetAssignmentHandler::__invoke()
  - tryAcquire(configName) → если занято: warn + return (ack без requeue)
  - startExecution(configName) → блокирует лок на 4 ч
  - release() в finally
        │
        ▼
AssetAssignmentService::startExecution()
  1. ConfigurationLoader::loadFiles() — пагинация по 200 штук через setLimit/setOffset
  2. FileGrouper::group() — группировка по базовому коду из имени файла
  3. array_chunk(10 групп) — батчи для DataObject lookup
  4. DataObjectFinder::findByMatchFieldBatch() — один IN(...) запрос на батч
  5. processFileGroup() — стратегия.moveFile() + attachmentHandler.attachFiles()
  6. Pimcore::collectGarbage() + gc_collect_cycles() + RuntimeCache::clear() — после каждого батча
  7. finalizeExecution() → dispatch(AssetAssignmentCompletedEvent)
        │
        ▼
App\EventSubscriber\AssetAssignmentSyncSubscriber (опционально)
  - слушает AssetAssignmentCompletedEvent
  - батчит изменённые объекты (500 шт) → ProductAttachmentSyncMessage
```

## Интеграция с приложением

Бандл публикует события — приложение решает, что с ними делать.

**`AssetAssignmentCompletedEvent`** — выполнение завершено. Содержит:
- `configName`
- `modifiedObjects` — массив `[id, className]` всех изменённых объектов
- `statistics` — счётчики (processed, moved, attached, unmatched, errors)
- `attachmentField`

**`AssetAssignmentCancelledEvent`** — запуск отменён через UI. Та же структура.

Подписка:
```php
use Iperson1337\AssetAssignmentBundle\Event\AssetAssignmentCompletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: AssetAssignmentCompletedEvent::class)]
final class MyAssetSyncSubscriber
{
    public function __invoke(AssetAssignmentCompletedEvent $event): void
    {
        // $event->getModifiedObjects(), $event->getStatistics() ...
    }
}
```

## Защита от конкурентного запуска

`ExecutionLockService` создаёт Redis-лок по ключу `asset_assignment_execution_<configName>` с TTL 4 ч.

- **Handler**: `tryAcquire()` перед `startExecution()`; если лок занят — дублирующее сообщение ackается и молча удаляется
- **Команда**: `tryAcquire()` перед запуском; если занято — ошибка и `Command::FAILURE`
- **Контроллер**: проверяет `isRunning` через Redis Cache (быстрый UX-guard); реальная защита — в Handler-е; кнопка "Отменить" вызывает `forceRelease()` для очистки лока даже после краша

TTL лока — **30 минут**. Если воркер был убит (`SIGKILL`, OOM) и лок завис, он автоматически истечёт через 30 мин. Для немедленной разблокировки используй кнопку "Отменить" в UI или вручную:
```bash
redis-cli DEL asset_assignment_execution_<configName>
```

## Защита от бесконечного цикла

`ConfigurationValidator::validateTargetNotInSource()` бросает `InfiniteLoopException`, если целевая папка вложена в исходную. Сравнение с trailing slash, чтобы `/import/Товары` не совпадало с `/import/ТоварыПолные`.

## Неприкреплённые файлы

Файлы, для которых не найден DataObject с нужным базовым кодом, **остаются в исходной папке** и повторно обрабатываются на следующем запуске. Каждый такой файл логируется как `warning`. Итоговый счётчик `unmatched_files` попадает в статистику события.

## Логирование

Компонент логирует через `LoggerInterface` (инжектируется через DI). Канал по умолчанию — `app`.

| Уровень | Когда |
|---------|-------|
| `info` | Запуск, завершение, прикрепление файла к объекту |
| `warning` | Файл не нашёл DataObject, дублирующее сообщение, ошибки транзакций |
| `error` | Неожиданное исключение в Handler |
| `debug` | Операции с Redis Cache (initProgress, updateProgress) |

## Добавление новой стратегии

1. Создайте класс, реализующий `FileMovementStrategyInterface`
2. Добавьте case в `FileMovementStrategyFactory::create()`
3. Добавьте строку в JS конфиг и переводы

## Лицензия

MIT
