<?php

namespace Iperson1337\AssetAssignmentBundle\Messenger\Handler;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Iperson1337\AssetAssignmentBundle\Message\AssetAssignmentMessage;
use Iperson1337\AssetAssignmentBundle\Service\AssetAssignmentService;
use Iperson1337\AssetAssignmentBundle\Service\ExecutionLockService;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AssetAssignmentHandler
{
    public function __construct(
        private AssetAssignmentService $assetAssignmentService,
        private LoggerInterface $logger,
        private ExecutionLockService $executionLockService
    ) {}

    /**
     * @throws \Exception
     * @throws InvalidArgumentException
     */
    public function __invoke(AssetAssignmentMessage $message): void
    {
        $configName = $message->configName;

        // Distributed lock prevents two workers from processing the same config simultaneously.
        // If a duplicate message is in the queue (e.g. from a double-click), ack it silently.
        if (!$this->executionLockService->tryAcquire($configName)) {
            $this->logger->warning('Skipping duplicate AssetAssignmentMessage — execution already locked', [
                'config_name' => $configName,
            ]);
            return;
        }

        $this->logger->info('Starting Asset Assignment execution', [
            'config_name' => $configName
        ]);

        try {
            $this->assetAssignmentService->startExecution($configName);

            $this->logger->info('Asset Assignment execution completed', [
                'config_name' => $configName
            ]);
        } catch (ConnectionException $e) {
            $this->logger->warning('Asset Assignment execution encountered transaction errors', [
                'config_name' => $configName,
                'error' => $e->getMessage()
            ]);
        } catch (LockWaitTimeoutException $e) {
            $this->logger->warning('Asset Assignment execution encountered lock timeout errors', [
                'config_name' => $configName,
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Asset Assignment execution failed', [
                'config_name' => $configName,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'exception_location' => $e->getFile() . ':' . $e->getLine(),
            ]);

            throw $e;
        } finally {
            $this->executionLockService->release($configName);
        }
    }
}
