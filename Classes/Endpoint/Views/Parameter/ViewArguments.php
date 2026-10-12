<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Parameter;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * The arguments a view is rendered with: ?arguments[fusionPath]=…&arguments[tags][]=a
 *
 * Only describes and validates the parameter: Schematic builds an object from its named constructor parameters, which a
 * free-form map has none of, so the operation reads the values from the query itself, as for DataSourceArguments
 */
final readonly class ViewArguments implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The arguments of the view by name, the Fusion context variable arguments. Which ones it takes is up to the view',
            additionalProperties: true,
        );
    }
}
