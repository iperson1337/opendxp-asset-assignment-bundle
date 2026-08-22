<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

use Iperson1337\AssetAssignmentBundle\Dto\ConfigurationDto;
use Iperson1337\AssetAssignmentBundle\Dto\ExecutionStatistics;
use Iperson1337\AssetAssignmentBundle\Event\AssetAssignmentCancelledEvent;
use Iperson1337\AssetAssignmentBundle\Event\AssetAssignmentCompletedEvent;
use Iperson1337\AssetAssignmentBundle\Exception\ConfigurationNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\FolderNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\InvalidConfigurationException;
use Iperson1337\AssetAssignmentBundle\Strategy\CreateFolderAndMoveStrategy;
use Iperson1337\AssetAssignmentBundle\Strategy\FileMovementStrategyFactory;
use Iperson1337\AssetAssignmentBundle\Strategy\FileMovementStrategyInterface;
use OpenDxp\Model\Asset;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class AssetAssignmentService implements AssetAssignmentServiceInterface
{
    const string PROGRESS_STORE_PREFIX = 'asset_assignment_progress_';

    private const int PROGRESS_CACHE_TTL = 3600;
    private const string ASSET_TYPE_FOLDER = 'folder';
    private const int GROUPS_PER_BATCH = 10;

    public function __construct(
        private ConfigurationLoader $configurationLoader,
        private FileGrouper $fileGrouper,
        private DataObjectFinder $dataObjectFinder,
        private FileMovementStrategyFactory $strategyFactory,
        private AttachmentHandler $attachmentHandler,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Initialize execution by counting files and setting up progress tracking
     *
     * @throws ConfigurationNotFoundException
     * @throws InvalidConfigurationException
     * @throws FolderNotFoundException
     * @throws InvalidArgumentException
     */
    public function initializeExecution(string $configName): void
    {
        $config = $this->configurationLoader->load($configName);

        // Count all files from source folder
        $listing = new Asset\Listing();
        $listing->setCondition('path LIKE ? AND type != ?', [
            $config->sourceFolderPath . '%',
            self::ASSET_TYPE_FOLDER
        ]);
        $totalItems = $listing->getTotalCount();

        // Initialize progress tracking with total count
        $this->initProgress($configName, $totalItems);
    }

    /**
     * Start execution of asset assignment process
     * @throws InvalidArgumentException
     */
    public function startExecution(string $configName): bool
    {
        try {
            $config = $this->configurationLoader->load($configName);

            // Load and group files (files streamed in batches internally)
            $files = $this->configurationLoader->loadFiles($config);
            $totalItems = count($files);

            $this->logger->info("Found {$totalItems} files in source folder");

            if ($totalItems === 0) {
                $this->logger->warning("No files found in source folder: {$config->sourceFolderPath}");
                $this->cleanupProgress($configName);
                return true;
            }

            $groupedFiles = $this->fileGrouper->group($files, $config);
            unset($files); // release file objects after grouping

            $groupedFilesCount = count($groupedFiles);
            $this->logger->info("Grouped {$totalItems} files into {$groupedFilesCount} groups");

            if ($groupedFilesCount === 0) {
                $this->logger->warning("No file groups created - all files may have been skipped");
                $this->cleanupProgress($configName);
                return true;
            }

            // Initialize progress if not already done (for direct startExecution calls)
            $key = self::PROGRESS_STORE_PREFIX . $configName;
            $progressItem = $this->cache->getItem($key);
            if (!$progressItem->isHit()) {
                $this->initProgress($configName, $totalItems);
                $this->logger->info("Progress initialized in startExecution", [
                    'config_name' => $configName,
                    'total_items' => $totalItems,
                ]);
            }

            $statistics = new ExecutionStatistics();
            $modifiedObjects = [];

            // Process groups in batches to control memory — preload DataObjects per batch
            $chunks = array_chunk($groupedFiles, self::GROUPS_PER_BATCH, true);
            unset($groupedFiles);

            foreach ($chunks as $chunkIndex => $groupChunk) {
                $preloadedObjects = $this->dataObjectFinder->findByMatchFieldBatch(
                    $config->dataObjectClass,
                    $config->matchField,
                    array_keys($groupChunk)
                );

                foreach ($groupChunk as $baseCode => $fileGroup) {
                    try {
                        $this->processFileGroup(
                            $config,
                            $baseCode,
                            $fileGroup,
                            $statistics,
                            $modifiedObjects,
                            $preloadedObjects[$baseCode] ?? null
                        );
                    } catch (\Exception $e) {
                        $statistics->addError("Error processing file group for base code '{$baseCode}': " . $e->getMessage());
                        $this->logger->error($e->getMessage(), ['exception' => $e]);
                    }
                }

                unset($preloadedObjects, $groupChunk);
                \OpenDxp::collectGarbage();
                gc_collect_cycles();
                \OpenDxp\Cache\RuntimeCache::clear();
            }

            $this->finalizeExecution($configName, $statistics, $modifiedObjects, $config);

            return true;

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $this->cleanupProgress($configName);

            return false;
        }
    }

    /**
     * Process file group for a specific base code
     * @throws \Exception
     */
    private function processFileGroup(
        ConfigurationDto $config,
        string $baseCode,
        array $fileGroup,
        ExecutionStatistics $statistics,
        array &$modifiedObjects,
        ?\OpenDxp\Model\DataObject\Concrete $preloadedDataObject = null
    ): void {
        $this->logger->info("Processing file group for base code: {$baseCode} ({count} files)", [
            'count' => count($fileGroup),
            'base_code' => $baseCode,
            'class' => $config->dataObjectClass,
        ]);

        // Use preloaded DataObject from batch query, or fall back to individual lookup
        $dataObject = $preloadedDataObject ?? $this->dataObjectFinder->findByMatchField(
            $config->dataObjectClass,
            $config->matchField,
            $baseCode
        );

        if (!$dataObject) {
            $filesCount = count($fileGroup);
            // Warning: unmatched files remain in source folder and will be re-processed on every run.
            // Monitor the count — if it grows, files need to be cleaned up or matched manually.
            $this->logger->warning(
                "No DataObject found with {$config->matchField} = '{$baseCode}' — {$filesCount} file(s) left in source folder and will be retried on next run",
                ['count' => $filesCount, 'base_code' => $baseCode, 'class' => $config->dataObjectClass]
            );
            
            $statistics->incrementUnmatchedFiles($filesCount);
            for ($i = 0; $i < $filesCount; $i++) {
                $statistics->incrementProcessed();
            }
            
            // Update progress even for skipped files
            $this->updateProgress(
                $config->configName,
                $statistics->getProcessed(),
                $statistics->getMovedFiles(),
                $statistics->getAttachedFiles(),
                []
            );
            
            return;
        }

        $folderName = $dataObject->{'get' . ucfirst($config->matchField)}();
        $this->logger->info("Found DataObject ID {$dataObject->getId()} with {$config->matchField}='{$folderName}' for base code '{$baseCode}'");

        // Move files using strategy
        $strategy = $this->strategyFactory->create($config->strategy, $config->configName);
        $createdFoldersInGroup = $this->moveFiles($fileGroup, $strategy, $config->targetFolder, $folderName, $statistics);

        // Update created folders count (strategy tracks exact number of folders created)
        for ($i = 0; $i < $createdFoldersInGroup; $i++) {
            $statistics->incrementCreatedFolders();
        }

        // Handle attachment if configured
        $newModifiedObjects = [];
        if ($config->attachmentField && $config->fieldDef) {
            $this->attachFiles($dataObject, $config, $fileGroup, $statistics);
            $newModifiedObjects[] = [
                'id' => $dataObject->getId(),
                'className' => $dataObject->getClassName()
            ];
            $modifiedObjects[] = $newModifiedObjects[0];
        }

        // Update progress with modified objects
        $this->updateProgress(
            $config->configName,
            $statistics->getProcessed(),
            $statistics->getMovedFiles(),
            $statistics->getAttachedFiles(),
            $newModifiedObjects
        );
    }

    /**
     * Move files using strategy
     *
     * @return int Number of created folders (if strategy supports it)
     */
    private function moveFiles(
        array $fileGroup,
        FileMovementStrategyInterface $strategy,
        Asset\Folder $targetFolder,
        string $folderName,
        ExecutionStatistics $statistics
    ): int {
        foreach ($fileGroup as $fileData) {
            $file = $fileData['file'];

            try {
                $strategy->moveFile($file, $targetFolder, $folderName);
                $statistics->incrementProcessed();
                $statistics->incrementMovedFiles();
            } catch (\Exception $e) {
                $statistics->addError("Failed to move file '{$file->getFilename()}': " . $e->getMessage());
                $this->logger->warning("Failed to move file '{$file->getFilename()}': " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $statistics->incrementProcessed();
            }
        }

        // Return created folders count from strategy if applicable
        if ($strategy instanceof CreateFolderAndMoveStrategy) {
            return $strategy->getCreatedFoldersCount();
        }

        return 0;
    }

    /**
     * Attach files to DataObject
     */
    private function attachFiles(
        \OpenDxp\Model\DataObject\Concrete $dataObject,
        ConfigurationDto $config,
        array $fileGroup,
        ExecutionStatistics $statistics
    ): void {
        try {
            $this->attachmentHandler->attachFiles(
                $dataObject,
                $config->attachmentField,
                $config->fieldDef,
                $fileGroup,
                $config->clearGalleryBeforeAssignment,
                $config->configName
            );

            $dataObject->setOmitMandatoryCheck(true);
            $dataObject->save(['versionNote' => 'Asset Assignment - auto-attached']);
            $statistics->incrementAttachedFiles();

            $this->logger->info("✓ Attached files to DataObject ID {$dataObject->getId()}, field '{$config->attachmentField}'");
        } catch (\Exception $e) {
            $statistics->addError("Failed to attach files to DataObject ID {$dataObject->getId()}: " . $e->getMessage());
            $this->logger->error("Failed to attach files to DataObject ID {$dataObject->getId()}: " . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Finalize execution: cleanup, dispatch event, log results
     */
    private function finalizeExecution(
        string $configName,
        ExecutionStatistics $statistics,
        array $modifiedObjects,
        ConfigurationDto $config
    ): void {
        $this->cleanupProgress($configName);

        if ($statistics->getUnmatchedFiles() > 0) {
            $this->logger->warning(
                "{$statistics->getUnmatchedFiles()} file(s) remain unmatched in source folder '{$config->sourceFolderPath}' and will be re-processed on next run",
                ['config_name' => $configName, 'unmatched_files' => $statistics->getUnmatchedFiles()]
            );
        }

        // Dispatch event with modified objects information
        $this->logger->info("Preparing to dispatch AssetAssignmentCompletedEvent", [
            'config_name' => $configName,
            'modified_objects_count' => count($modifiedObjects),
            'attachment_field' => $config->attachmentField
        ]);

        if (!empty($modifiedObjects)) {
            $eventStatistics = $statistics->toArray();

            $event = new AssetAssignmentCompletedEvent(
                $configName,
                $modifiedObjects,
                $eventStatistics,
                $config->attachmentField
            );

            $eventName = AssetAssignmentCompletedEvent::class;
            $this->logger->info("Calling eventDispatcher->dispatch", [
                'config_name' => $configName,
                'event_class' => get_class($event),
                'event_name' => $eventName
            ]);

            // Try dispatching with an explicit event name (for Contracts EventDispatcher)
            $dispatchedEvent = $this->eventDispatcher->dispatch($event, $eventName);

            $this->logger->info("Event dispatched, checking if event was stopped", [
                'config_name' => $configName,
                'event_class' => get_class($dispatchedEvent),
                'is_propagation_stopped' => method_exists($dispatchedEvent, 'isPropagationStopped') ? $dispatchedEvent->isPropagationStopped() : 'unknown'
            ]);

            $this->logger->info("Dispatched AssetAssignmentCompletedEvent", [
                'config_name' => $configName,
                'modified_objects_count' => count($modifiedObjects),
                'statistics' => $eventStatistics
            ]);
        } else {
            $this->logger->info("Skipping event dispatch - no modified objects", [
                'config_name' => $configName
            ]);
        }
    }

    /**
     * Get current execution status
     */
    public function getExecutionStatus(string $configName): array
    {
        $key = self::PROGRESS_STORE_PREFIX . $configName;
        $item = $this->cache->getItem($key);

        $this->logger->debug("getExecutionStatus: checking cache", [
            'key' => $key,
            'isHit' => $item->isHit()
        ]);

        if ($item->isHit()) {
            $data = $item->get();

            $this->logger->debug("getExecutionStatus: loaded from cache", [
                'key' => $key,
                'data' => $data
            ]);

            $totalItems = (int)($data['total'] ?? 0);
            $processedItems = (int)($data['processed'] ?? 0);
            $movedFiles = (int)($data['movedFiles'] ?? 0);
            $attachedFiles = (int)($data['attachedFiles'] ?? 0);
            $modifiedObjects = $data['modifiedObjects'] ?? [];
            $progress = $totalItems > 0 ? min(1, round($processedItems / $totalItems, 2)) : 0;
            $isRunning = $processedItems < $totalItems && $totalItems > 0;
        } else {
            $totalItems = 0;
            $processedItems = 0;
            $movedFiles = 0;
            $attachedFiles = 0;
            $modifiedObjects = [];
            $progress = 0;
            $isRunning = false;
        }

        return [
            'isRunning' => $isRunning,
            'totalItems' => $totalItems,
            'processedItems' => $processedItems,
            'movedFiles' => $movedFiles,
            'attachedFiles' => $attachedFiles,
            'modifiedObjects' => $modifiedObjects,
            'progress' => $progress
        ];
    }

    /**
     * Cancel execution and cleanup
     */
    public function cancelExecutionAndCleanup(string $configName): void
    {
        // Get current status and modified objects from cache before cleanup
        $key = self::PROGRESS_STORE_PREFIX . $configName;
        $item = $this->cache->getItem($key);

        $status = null;
        $modifiedObjects = [];
        $statistics = [];

        if ($item->isHit()) {
            $data = $item->get();
            $status = [
                'totalItems' => (int)($data['total'] ?? 0),
                'processedItems' => (int)($data['processed'] ?? 0),
                'movedFiles' => (int)($data['movedFiles'] ?? 0),
                'attachedFiles' => (int)($data['attachedFiles'] ?? 0),
            ];
            $modifiedObjects = $data['modifiedObjects'] ?? [];

            // Build statistics array
            $statistics = [
                'processed' => $status['processedItems'],
                'movedFiles' => $status['movedFiles'],
                'attachedFiles' => $status['attachedFiles'],
                'totalItems' => $status['totalItems'],
                'createdFolders' => 0, // Not tracked in progress
                'errors' => []
            ];
        }

        // Cleanup progress
        $this->cleanupProgress($configName);

        // Load configuration to get attachmentField
        $attachmentField = null;
        try {
            $config = $this->configurationLoader->load($configName);
            $attachmentField = $config->attachmentField;
        } catch (ConfigurationNotFoundException | InvalidConfigurationException $e) {
            $this->logger->warning("Could not load configuration for cancelled event", [
                'config_name' => $configName,
                'error' => $e->getMessage()
            ]);
        }

        // Dispatch cancellation event
        $event = new AssetAssignmentCancelledEvent(
            $configName,
            $modifiedObjects,
            $statistics,
            $attachmentField
        );

        $eventName = AssetAssignmentCancelledEvent::class;
        $this->eventDispatcher->dispatch($event, $eventName);

        $this->logger->info("Dispatched AssetAssignmentCancelledEvent", [
            'config_name' => $configName,
            'modified_objects_count' => count($modifiedObjects),
            'statistics' => $statistics
        ]);
    }

    /**
     * Initialize progress tracking
     * @throws InvalidArgumentException
     */
    protected function initProgress(string $configName, int $totalItems): void
    {
        $data = [
            'total' => $totalItems,
            'processed' => 0,
            'movedFiles' => 0,
            'attachedFiles' => 0,
            'modifiedObjects' => []
        ];

        $key = self::PROGRESS_STORE_PREFIX . $configName;
        $this->cache->delete($key); // Clear any existing data

        $item = $this->cache->getItem($key);
        $item->set($data);
        $item->expiresAfter(self::PROGRESS_CACHE_TTL);
        $this->cache->save($item);

        $this->logger->debug("initProgress: saved to cache", [
            'key' => $key,
            'data' => $data
        ]);
    }

    /**
     * Update progress
     * Creates progress entry if it doesn't exist to ensure progress is always visible
     */
    protected function updateProgress(string $configName, int $processedItems, int $movedFiles = 0, int $attachedFiles = 0, array $modifiedObjects = []): void
    {
        $key = self::PROGRESS_STORE_PREFIX . $configName;
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            $data = $item->get();
            $data['processed'] = $processedItems;
            $data['movedFiles'] = $movedFiles;
            $data['attachedFiles'] = $attachedFiles;
            if (!isset($data['total'])) {
                $data['total'] = max($processedItems, 1);
            }
            // Merge modified objects, avoiding duplicates by object ID
            if (!empty($modifiedObjects)) {
                if (!isset($data['modifiedObjects'])) {
                    $data['modifiedObjects'] = [];
                }
                // Create a map of existing objects by ID to avoid duplicates
                $existingIds = [];
                foreach ($data['modifiedObjects'] as $obj) {
                    $existingIds[$obj['id']] = true;
                }
                // Add new objects that don't already exist
                foreach ($modifiedObjects as $obj) {
                    if (!isset($existingIds[$obj['id']])) {
                        $data['modifiedObjects'][] = $obj;
                        $existingIds[$obj['id']] = true;
                    }
                }
            }

            $item->set($data);
            $item->expiresAfter(self::PROGRESS_CACHE_TTL);
            $this->cache->save($item);

            $this->logger->debug("updateProgress: saved to cache", [
                'key' => $key,
                'data' => $data
            ]);
        } else {
            // Create a progress entry if it doesn't exist to ensure progress is always visible
            $data = [
                'total' => max($processedItems, 1),
                'processed' => $processedItems,
                'movedFiles' => $movedFiles,
                'attachedFiles' => $attachedFiles,
                'modifiedObjects' => $modifiedObjects
            ];

            $item->set($data);
            $item->expiresAfter(self::PROGRESS_CACHE_TTL);
            $this->cache->save($item);

            $this->logger->info("updateProgress: created progress entry (was missing)", [
                'key' => $key,
                'data' => $data
            ]);
        }
    }

    /**
     * Cleanup progress tracking
     * @throws InvalidArgumentException
     */
    protected function cleanupProgress(string $configName): void
    {
        $key = self::PROGRESS_STORE_PREFIX . $configName;
        $this->cache->delete($key);

        $this->logger->debug("cleanupProgress: removed from cache", [
            'key' => $key
        ]);
    }
}
