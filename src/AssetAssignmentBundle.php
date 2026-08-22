<?php

namespace Iperson1337\AssetAssignmentBundle;

use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\Extension\Bundle\OpenDxpBundleAdminClassicInterface;
use OpenDxp\Extension\Bundle\Traits\BundleAdminClassicTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class AssetAssignmentBundle extends AbstractOpenDxpBundle implements OpenDxpBundleAdminClassicInterface
{
    use BundleAdminClassicTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        // Explicitly return our extension to allow a custom alias (iperson1337_asset_assignment)
        return new DependencyInjection\AssetAssignmentExtension();
    }

    /**
     * Register JS for Data Hub adapter integration.
     * The path is relative to the public/ directory after assets:install, which publishes
     * bundle assets under /bundles/assetassignment/ by convention (short bundle name, lowercased).
     */
    public function getJsPaths(): array
    {
        return [
            '/bundles/assetassignment/js/pimcore/configuration/execution.js',
            '/bundles/assetassignment/js/pimcore/configuration/configItem.js',
            '/bundles/assetassignment/js/pimcore/adapter/assetAssignment.js',
        ];
    }

    /**
     * Optional CSS for Data Hub adapter icon.
     */
    public function getCssPaths(): array
    {
        return [
            '/bundles/assetassignment/css/assetAssignment.css',
        ];
    }

    /**
     * Register translations for the bundle.
     */
    public function getTranslationPaths(): array
    {
        return [
            $this->getPath() . '/translations',
        ];
    }
}
