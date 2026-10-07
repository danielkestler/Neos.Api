<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Parameter;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * The arguments a data source is called with: ?arguments[parentCategory]=news&arguments[tags][]=a
 *
 * Only describes and validates the parameter: Schematic builds an object from its named constructor parameters, which a
 * free-form map has none of, so the operation reads the values from the query itself
 */
final readonly class DataSourceArguments implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The arguments of the data source by name, what the Neos UI sends from editorOptions.dataSourceAdditionalData',
            additionalProperties: true,
        );
    }
}
