<?php
declare(strict_types=1);

namespace Neos\Api\Shared\Params;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Which page of a list, as JSON:API's page family with the offset strategy: ?page[offset]=50&page[limit]=25
 */
final readonly class Page implements ProvidesSchema
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $offset = 0,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }

    public function toPagination(): Pagination
    {
        return Pagination::fromLimitAndOffset($this->limit, $this->offset);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Which page of the list: page[offset] items skipped, page[limit] items at most',
            properties: ObjectProperties::create(
                offset: IntegerSchema::create(description: 'How many items to skip', default: 0, minimum: 0),
                limit: IntegerSchema::create(description: 'How many items at most', default: self::DEFAULT_LIMIT, minimum: 1, maximum: self::MAX_LIMIT),
            ),
            additionalProperties: false,
        );
    }
}
