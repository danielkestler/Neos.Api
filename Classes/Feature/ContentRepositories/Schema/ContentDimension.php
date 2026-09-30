<?php
declare(strict_types=1);

namespace Neos\Api\Feature\ContentRepositories\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core\Dimension;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A content dimension of a content repository, e.g. the language
 */
final readonly class ContentDimension implements ProvidesSchema
{
    /**
     * @param string $id the dimension's key in the contentDimensions settings, e.g. language
     * @param string|null $label the label of the configuration in the requested language, null if it has none
     * @param ContentDimensionValues $values in the order of the configuration, generalizations before their specializations
     */
    public function __construct(
        public string $id,
        public string|null $label,
        public ContentDimensionValues $values,
    ) {
    }

    public static function from(Dimension\ContentDimension $dimension, Labels $labels): self
    {
        return new self(
            $dimension->id->value,
            $labels->label($dimension->getConfigurationValue('label')),
            new ContentDimensionValues(...array_map(
                static fn (Dimension\ContentDimensionValue $value) => ContentDimensionValue::from($value, $dimension, $labels),
                array_values($dimension->values->values),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
