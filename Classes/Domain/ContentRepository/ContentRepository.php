<?php
declare(strict_types=1);

namespace Neos\Api\Domain\ContentRepository;

use Neos\ContentRepository\Core\ContentRepository as NeosContentRepository;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A content repository with its content dimensions
 */
final readonly class ContentRepository implements ProvidesSchema
{
    /**
     * @param ContentDimensions $dimensions ordered by priority, empty if the content repository has none
     */
    public function __construct(
        public ContentRepositoryId $id,
        public ContentDimensions $dimensions,
    ) {
    }

    public static function fromNeosContentRepository(NeosContentRepository $contentRepository): self
    {
        return new self(
            ContentRepositoryId::fromString($contentRepository->id->value),
            new ContentDimensions(...array_map(
                ContentDimension::fromNeosContentDimension(...),
                array_values($contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority()),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
