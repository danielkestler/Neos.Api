<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core;
use Neos\ContentRepository\Core\Dimension;
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

    public static function from(Core\ContentRepository $contentRepository, Labels $labels): self
    {
        return new self(
            ContentRepositoryId::fromString($contentRepository->id->value),
            new ContentDimensions(...array_map(
                static fn (Dimension\ContentDimension $dimension) => ContentDimension::from($dimension, $labels),
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
