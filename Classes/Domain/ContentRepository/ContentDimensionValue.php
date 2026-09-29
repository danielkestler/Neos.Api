<?php
declare(strict_types=1);

namespace Neos\Api\Domain\ContentRepository;

use Neos\ContentRepository\Core\Dimension\ContentDimension as NeosContentDimension;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValue as NeosContentDimensionValue;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A value of a content dimension, e.g. a language
 */
final readonly class ContentDimensionValue implements ProvidesSchema
{
    /**
     * @param string $value e.g. en_US
     * @param string|null $label the label of the configuration, null if it has none
     * @param string|null $generalization the value it falls back to, null for a root value
     */
    public function __construct(
        public string $value,
        public string|null $label,
        public string|null $generalization,
    ) {
    }

    public static function fromNeosContentDimensionValue(NeosContentDimensionValue $value, NeosContentDimension $dimension): self
    {
        return new self(
            $value->value,
            ContentDimension::label($value->getConfigurationValue('label')),
            $dimension->getGeneralization($value)?->value,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
