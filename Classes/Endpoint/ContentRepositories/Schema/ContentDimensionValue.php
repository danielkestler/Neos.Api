<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core\Dimension;
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
     * @param string|null $label the label of the configuration in the requested language, null if it has none
     * @param string|null $generalization the value it falls back to, null for a root value
     */
    public function __construct(
        public string $value,
        public string|null $label,
        public string|null $generalization,
    ) {
    }

    public static function from(Dimension\ContentDimensionValue $value, Dimension\ContentDimension $dimension, Labels $labels): self
    {
        return new self(
            $value->value,
            $labels->label($value->getConfigurationValue('label')),
            $dimension->getGeneralization($value)?->value,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
