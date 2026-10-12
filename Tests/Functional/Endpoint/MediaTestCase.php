<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * Media tests: assets to work with, and what changing them needs
 */
abstract class MediaTestCase extends EndpointTestCase
{
    protected const string UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    /**
     * A PDF document asset, added to the repository, not persisted yet
     */
    protected function addDocument(string $filename): Document
    {
        $resource = $this->objectManager->get(ResourceManager::class)->importResourceFromContent('%PDF-1.4 ' . $filename, $filename);
        $document = new Document($resource);
        $this->objectManager->get(AssetRepository::class)->add($document);
        return $document;
    }

    /**
     * A 1×1 PNG image asset, added to the repository, not persisted yet
     */
    protected function addImage(string $filename): Image
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
        $resource = $this->objectManager->get(ResourceManager::class)->importResourceFromContent($png, $filename);
        $image = new Image($resource);
        $this->objectManager->get(AssetRepository::class)->add($image);
        return $image;
    }

    /**
     * Changing or deleting an asset makes Neos look up its usages and flush their content caches, and the usages are
     * read in their workspaces: that needs the content repository, and Neos' tables for asset usages and workspace
     * metadata and roles, which migrations create, not the entities
     */
    protected function requireContentRepository(): void
    {
        $connection = $this->objectManager->get(EntityManagerInterface::class)->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
        $this->objectManager->get(ContentRepositoryRegistry::class)->buildService(ContentRepositoryId::fromString('default'), new ContentRepositoryMaintainerFactory())->setUp();
        // as Neos.Neos' migration Version20240906102606
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_asset_usage` (
                `contentrepositoryid` char(16) DEFAULT NULL,
                `assetid` varchar(40) NOT NULL DEFAULT '',
                `originalassetid` varchar(40) DEFAULT NULL,
                `workspacename` char(36) NOT NULL,
                `nodeaggregateid` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `origindimensionspacepoint` json DEFAULT (JSON_OBJECT()),
                `origindimensionspacepointhash` varbinary(32) NOT NULL DEFAULT '',
                `propertyname` varchar(255) NOT NULL DEFAULT '',
                UNIQUE KEY `IDX_14C94F11044B499EB28F27DAEAC5D4BB` (`contentrepositoryid`, `assetid`, `originalassetid`, `workspacename`, `nodeaggregateid`, `origindimensionspacepointhash`, `propertyname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        // as Neos.Neos' migrations Version20240425223900 and Version20241212000000
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_neos_workspace_metadata` (
                `content_repository_id` varchar(16) NOT NULL,
                `workspace_name` varchar(255) NOT NULL,
                `title` varchar(255) NOT NULL,
                `description` longtext NOT NULL,
                `classification` varchar(255) NOT NULL,
                `owner_user_id` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`content_repository_id`, `workspace_name`),
                UNIQUE KEY `owner` (`content_repository_id`, `owner_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_neos_workspace_role` (
                `content_repository_id` varchar(16) NOT NULL,
                `workspace_name` varchar(255) NOT NULL,
                `subject_type` varchar(20) NOT NULL,
                `subject` varchar(255) NOT NULL,
                `role` varchar(20) NOT NULL,
                PRIMARY KEY (`content_repository_id`, `workspace_name`, `subject_type`, `subject`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        foreach (['neos_asset_usage', 'neos_neos_workspace_metadata', 'neos_neos_workspace_role'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
    }
}
