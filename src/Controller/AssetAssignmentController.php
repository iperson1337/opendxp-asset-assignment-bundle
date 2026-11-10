<?php

namespace Iperson1337\AssetAssignmentBundle\Controller;

use Iperson1337\AssetAssignmentBundle\Exception\ConfigurationNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\InvalidConfigurationException;
use Iperson1337\AssetAssignmentBundle\Exception\FolderNotFoundException;
use Iperson1337\AssetAssignmentBundle\Message\AssetAssignmentMessage;
use Iperson1337\AssetAssignmentBundle\Service\AssetAssignmentService;
use Iperson1337\AssetAssignmentBundle\Service\ExecutionLockService;
use OpenDxp\Bundle\DataHubBundle\Configuration;
use OpenDxp\Controller\KernelControllerEventInterface;
use OpenDxp\Controller\UserAwareController;
use OpenDxp\Model\DataObject\ClassDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/asset-assignment')]
final class AssetAssignmentController extends UserAwareController implements KernelControllerEventInterface
{
    public const PERMISSION = 'plugin_datahub_config';

    public function __construct(
        protected AssetAssignmentService $assetAssignmentService,
        protected MessageBusInterface $messageBus,
        protected LoggerInterface $logger,
        protected ExecutionLockService $executionLockService,
    ) {
    }

    /**
     * Права для всех экшенов контроллера разом: право `plugin_datahub_config` и право чтения
     * конфигурации, названной в запросе, — как при открытии её в Data Hub. Имя проверяется и из
     * тела, и из строки запроса: экшены читают его из разных мест.
     */
    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->checkPermission(self::PERMISSION);

        $request = $event->getRequest();
        foreach ([$request->request->get('config_name'), $request->query->get('config_name')] as $configName) {
            if (!is_string($configName) || $configName === '') {
                continue;
            }

            $configuration = Configuration::getByName($configName);
            if ($configuration !== null && !$configuration->isAllowed('read')) {
                throw new AccessDeniedHttpException(sprintf('No read permission for configuration `%s`', $configName));
            }
        }
    }

    #[Route('/start-execution', methods: ['POST'], options: ['expose' => true])]
    public function startExecutionAction(Request $request): JsonResponse
    {
        try {
            $configName = $request->request->get('config_name');
            
            if (empty($configName)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Configuration name is required',
                    'code' => 'MISSING_CONFIG_NAME'
                ], 400);
            }

            // Guard against double-dispatch: if a worker is already processing this config,
            // reject the request. Note: this is a best-effort check (not a distributed lock),
            // so a very narrow race between two simultaneous clicks can still slip through.
            $status = $this->assetAssignmentService->getExecutionStatus($configName);
            if ($status['isRunning']) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Execution already in progress for this configuration',
                    'code' => 'ALREADY_RUNNING',
                ], 409);
            }

            // Initialize progress before starting async execution
            $this->assetAssignmentService->initializeExecution($configName);

            // Dispatch message to Symfony Messenger for async execution
            $message = new AssetAssignmentMessage($configName);
            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
            ]);

        } catch (ConfigurationNotFoundException $e) {
            $this->logger->warning('Configuration not found', ['config' => $configName ?? 'unknown']);
            return new JsonResponse([
                'success' => false,
                'message' => 'Configuration not found',
                'code' => 'CONFIG_NOT_FOUND'
            ], 404);
        } catch (InvalidConfigurationException $e) {
            $this->logger->warning('Invalid configuration', [
                'config' => $configName ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'INVALID_CONFIG'
            ], 400);
        } catch (FolderNotFoundException $e) {
            $this->logger->warning('Folder not found', [
                'config' => $configName ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'FOLDER_NOT_FOUND'
            ], 404);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in startExecutionAction', [
                'config' => $configName ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'exception_location' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Internal server error',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    #[Route('/check-execution-progress', methods: ['GET'], options: ['expose' => true])]
    public function checkExecutionProgressAction(Request $request): JsonResponse
    {
        $configName = $request->query->get('config_name');

        if (empty($configName)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Configuration name is required'
            ]);
        }

        $status = $this->assetAssignmentService->getExecutionStatus($configName);

        return new JsonResponse($status);
    }

    #[Route('/cancel-execution', methods: ['PUT'], options: ['expose' => true])]
    public function cancelExecutionAction(Request $request): JsonResponse
    {
        try {
            $configName = $request->request->get('config_name');

            if (empty($configName)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Configuration name is required',
                    'code' => 'MISSING_CONFIG_NAME'
                ], 400);
            }

            $this->executionLockService->forceRelease($configName);
            $this->assetAssignmentService->cancelExecutionAndCleanup($configName);

            return new JsonResponse([
                'success' => true
            ]);

        } catch (ConfigurationNotFoundException $e) {
            $this->logger->warning('Configuration not found for cancel', ['config' => $configName]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Configuration not found',
                'code' => 'CONFIG_NOT_FOUND'
            ], 404);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in cancelExecutionAction', [
                'config' => $configName ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'exception_location' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Internal server error',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    #[Route('/load-media-fields', methods: ['GET'], options: ['expose' => true])]
    public function loadMediaFieldsAction(Request $request): JsonResponse
    {
        try {
            $classId = $request->query->get('class_id');
            
            if (empty($classId)) {
                return new JsonResponse(['attributes' => []]);
            }

            $class = ClassDefinition::getById($classId);
            if (!$class) {
                return new JsonResponse(['attributes' => []]);
            }
            
            $attributes = [];
            $mediaFieldTypes = ['image', 'imageGallery', 'hotspotimage', 'manyToManyRelation', 'advancedManyToManyRelation'];
            
            foreach ($class->getFieldDefinitions() as $field) {
                if (in_array($field->getFieldtype(), $mediaFieldTypes)) {
                    // For manyToManyRelation, check if it accepts assets
                    if (in_array($field->getFieldtype(), ['manyToManyRelation', 'advancedManyToManyRelation'])) {
                        $classes = method_exists($field, 'getClasses') ? $field->getClasses() : [];
                        if (!empty($classes) && !in_array('asset', $classes)) {
                            continue;
                        }
                    }
                    
                    $attributes[] = [
                        'key' => $field->getName(),
                        'name' => $field->getTitle() . ' [' . $field->getName() . ']'
                    ];
                }
            }
            
            return new JsonResponse(['attributes' => $attributes]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'attributes' => []
            ]);
        }
    }
}

