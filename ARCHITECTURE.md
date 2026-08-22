# Asset Assignment Bundle — Architecture

## Design Principles

- **Standalone**: no dependencies on the host application (`App\`). Integration is events-only.
- **Strategy Pattern**: file movement is pluggable; adding a new strategy touches one factory method.
- **Batched processing**: files are loaded page by page; DataObjects are fetched in a single `IN(...)` query per 100-group chunk; memory is released after every chunk.
- **Distributed lock**: a Redis-backed lock prevents concurrent runs from the same config across workers and the CLI.

---

## Component Map

```
src/
├── AssetAssignmentBundle.php
│
├── Command/
│   └── ExecuteAssignmentCommand          CLI entry-point; acquires lock, calls Service
│
├── Controller/
│   └── AssetAssignmentController         REST API; UX guard via isRunning; dispatches Message
│
├── DependencyInjection/
│   ├── AssetAssignmentExtension          Loads config/services.yaml
│   └── Configuration                     Bundle config tree (currently empty, reserved)
│
├── Dto/
│   ├── ConfigurationDto                  Immutable value object: one validated profile
│   ├── ExecutionProgress                 Redis cache snapshot (isRunning, processed, ...)
│   ├── ExecutionStatistics               In-run counters (processed, moved, attached, unmatched, errors)
│   ├── FileGroupItem                     Single file entry inside a group
│   └── ModifiedObjectInfo               {id, className} of a DataObject touched during a run
│
├── Event/
│   ├── AssetAssignmentCompletedEvent     Dispatched at end of successful run
│   └── AssetAssignmentCancelledEvent     Dispatched when user cancels via UI
│
├── Exception/
│   ├── AssetAssignmentException          Base
│   ├── AttachmentException
│   ├── ConfigurationNotFoundException
│   ├── FolderNotFoundException
│   ├── InfiniteLoopException             Source folder contains target folder
│   ├── InvalidConfigurationException
│   └── InvalidFieldTypeException
│
├── Message/
│   └── AssetAssignmentMessage            {configName} — payload for Messenger transport
│
├── Messenger/Handler/
│   └── AssetAssignmentHandler            Acquires lock → calls startExecution → releases lock
│
├── Service/
│   ├── AssetAssignmentServiceInterface
│   ├── AssetAssignmentService            Orchestrates the full pipeline (594 lines)
│   ├── AttachmentHandler                 Attaches assets to DataObject fields (196 lines)
│   ├── ConfigurationLoader               Loads/validates profile; paginates Asset listing (170 lines)
│   ├── DataObjectFinder                  Single and batch DataObject lookup (106 lines)
│   ├── ExecutionLockService              Redis distributed lock, 4 h TTL (52 lines)
│   └── FileGrouper                       Groups files by base code extracted from filename (100 lines)
│
├── Strategy/
│   ├── FileMovementStrategyInterface     moveFile(Asset, Folder, string): Asset
│   ├── CreateFolderAndMoveStrategy       Creates subfolder per match value; caches folders in memory
│   ├── MoveToTargetStrategy              Flat move into target folder
│   └── FileMovementStrategyFactory       match($strategy) → strategy instance
│
└── Validator/
    └── ConfigurationValidator            Validates all profile fields; throws typed exceptions
```

---

## Execution Pipeline

### Entry points

Both entry points converge on the same lock + service call:

```
Controller ──► initializeExecution() ──► dispatch(Message)
                                                │
                                                ▼
                                        AssetAssignmentHandler
                                          tryAcquire(configName)  ← Redis lock
                                          startExecution(configName)
                                          release(configName)  ← always (finally)

Command ──► tryAcquire(configName)
            initializeExecution()
            startExecution(configName)
            release(configName)  ← always (finally)
```

The Controller does **not** acquire the lock — it only checks `isRunning` from the Redis progress key as a cheap UX guard to block obvious double-clicks. The Handler is the real serialization point.

### `startExecution()` internals

```
1. ConfigurationLoader.load(configName)
      └─ ConfigurationValidator.validate(profile)
            ├─ source/target folders exist
            ├─ target not inside source  (trailing-slash comparison → no sibling false-positive)
            ├─ DataObject class exists
            ├─ match field exists on class
            └─ attachment field exists on class (if set)

2. ConfigurationLoader.loadFiles(config)
      └─ loadFilesInBatches()
            └─ Asset\Listing  setLimit/setOffset/setOrderKey('id')
               yields batches of 200, aggregated into a flat array

3. FileGrouper.group(files, config)
      └─ strips extension, lowercases → base code → map[baseCode][]

4. array_chunk(groupedFiles, 100, true)
   ↓ per chunk:

5. DataObjectFinder.findByMatchFieldBatch(class, field, baseCodes)
      └─ single SQL  WHERE field IN (...)  via DataObject Listing class
         returns map[baseCode => DataObject]

6. per group in chunk → processFileGroup()
      ├─ strategy.moveFile()         (CreateFolderAndMoveStrategy | MoveToTargetStrategy)
      └─ attachmentHandler.attachFiles()  + dataObject.save()

7. unset(preloadedObjects, groupChunk)
   Pimcore::collectGarbage()
   gc_collect_cycles()
   RuntimeCache::clear()

8. finalizeExecution()
      ├─ log unmatched_files count (warning if > 0)
      └─ dispatch(AssetAssignmentCompletedEvent)
```

---

## Key Design Decisions

### Batched file pagination

`Asset\Listing::getTotalCount()` + full load is O(N) memory. Instead `loadFilesInBatches()` pages with `setLimit(200)/setOffset(n)` and yields one batch at a time. `loadFiles()` aggregates them for compatibility with the rest of the pipeline.

### N+1 elimination

`DataObjectFinder::findByMatchFieldBatch()` resolves a whole 100-group chunk with a single `IN(...)` query using the DataObject's auto-generated `Listing` class (`ClassName\Listing`). Falls back to individual `findByMatchField()` calls only if the Listing class is missing or throws.

### Folder cache in `CreateFolderAndMoveStrategy`

`Asset::getByPath()` is a DB call. The strategy caches resolved folder objects in `private array $folderCache` keyed by full path. Within one strategy instance (one execution), each unique target subfolder triggers exactly one DB lookup.

### Distributed lock

`ExecutionLockService` wraps `symfony/lock` with a `RedisStore`:
- Lock key: `asset_assignment_execution_{configName}`
- TTL: 14 400 s (4 h) — covers any realistic run; no refresh required
- Non-blocking `acquire(false)` — never waits, never deadlocks
- `autoRelease: false` — explicit `release()` in `finally`

If a lock cannot be acquired in the **Handler**, the message is acked and silently dropped (no requeue). If it cannot be acquired in the **Command**, execution fails with an error message.

### Infinite-loop prevention

`ConfigurationValidator::validateTargetNotInSource()` uses trailing-slash comparison:
```php
str_starts_with($targetPath . '/', $sourcePath . '/')
```
Bare `str_starts_with` would falsely match `/import/Товары` against `/import/ТоварыПолные`.

### Exception taxonomy

All exceptions extend `AssetAssignmentException`. The Controller catches each type individually to return the appropriate HTTP status (400/404/409/500). The Handler lets `\Exception` bubble (triggering Messenger dead-letter) but absorbs `ConnectionException` and `LockWaitTimeoutException` to avoid requeue loops.

---

## Event Contract

Events are dispatched via `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` — the bundle does not know anything about the host application.

```php
AssetAssignmentCompletedEvent
    string  $configName
    array   $modifiedObjects    // [{id: int, className: string}, ...]
    array   $statistics         // processed, moved_files, attached_files, unmatched_files, errors
    ?string $attachmentField

AssetAssignmentCancelledEvent   // same shape
```

Subscribe in the application:
```php
use Iperson1337\AssetAssignmentBundle\Event\AssetAssignmentCompletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: AssetAssignmentCompletedEvent::class)]
final class MySubscriber
{
    public function __invoke(AssetAssignmentCompletedEvent $event): void { ... }
}
```

---

## DI Configuration (`config/services.yaml`)

The lock stack is wired manually; everything else is autowired:

```yaml
asset_assignment.redis_connection:
    class: \Redis
    factory: ['Symfony\Component\Cache\Adapter\RedisAdapter', 'createConnection']
    arguments: ['%env(REDIS_DSN)%']

asset_assignment.lock_store:
    class: Symfony\Component\Lock\Store\RedisStore
    arguments: ['@asset_assignment.redis_connection']

asset_assignment.lock_factory:
    class: Symfony\Component\Lock\LockFactory
    arguments: ['@asset_assignment.lock_store']

Iperson1337\AssetAssignmentBundle\Service\ExecutionLockService:
    arguments:
        $lockFactory: '@asset_assignment.lock_factory'
```

`ExecutionLockService` accepts a `LockFactory` in the constructor, which makes it unit-testable with `InMemoryStore` without a Redis connection.

---

## Adding a New File Movement Strategy

```php
// 1. Implement the interface
final class CopyToTargetStrategy implements FileMovementStrategyInterface
{
    public function moveFile(Asset $file, Asset\Folder $targetFolder, string $folderName): Asset { ... }
    public function getName(): string { return 'copy_to_target'; }
}

// 2. Add a case in FileMovementStrategyFactory::create()
return match ($strategyName) {
    'create_folder_and_move' => new CreateFolderAndMoveStrategy(...),
    'move_to_target'         => new MoveToTargetStrategy(...),
    'copy_to_target'         => new CopyToTargetStrategy(...),   // ← add here
    default => throw new \InvalidArgumentException("Unknown strategy: {$strategyName}"),
};

// 3. Add the option to the JS config dropdown and translations
```

---

## Test Coverage

| Class | Test file | Notes |
|-------|-----------|-------|
| `ExecutionLockService` | `tests/Unit/Service/AssetAssignment/ExecutionLockServiceTest.php` | InMemoryStore, 5 cases |
| `ExecutionStatistics` | `tests/Unit/Service/AssetAssignment/ExecutionStatisticsTest.php` | Pure DTO, 9 cases |
| `DataObjectFinder` | `tests/Unit/Service/AssetAssignment/DataObjectFinderTest.php` | Stub Listing classes, 9 cases |
| `CreateFolderAndMoveStrategy` | `tests/Unit/Strategy/AssetAssignment/CreateFolderAndMoveStrategyTest.php` | 3 cases |
| `ConfigurationValidator` | — | Not yet covered |
| `AssetAssignmentService` | — | Requires Pimcore bootstrap |
| `AssetAssignmentHandler` | — | Integration (lock + real Messenger worker) |
