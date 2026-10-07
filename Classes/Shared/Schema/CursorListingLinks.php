<?php
declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\Api\Shared\Parameter\Limit;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages of a list paged with a cursor instead of an offset: the request's URL with ?after= the last item of a page
 *
 * For lists that can only be read from a position on, not counted, so there are no first, prev and last links
 */
final readonly class CursorListingLinks implements ProvidesSchema
{
    public function __construct(
        public string $self,
        public string|null $next,
    ) {
    }

    /**
     * @param int|null $nextAfter the cursor of the last item of this page if there are more, else null
     */
    public static function for(ServerRequestInterface $request, Limit $limit, int|null $nextAfter): self
    {
        $with = static function (array $changes) use ($request, $limit): string {
            $query = array_replace($request->getQueryParams(), ['limit' => $limit->value], $changes);
            return (string)$request->getUri()->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        };
        return new self(
            $with([]),
            $nextAfter !== null ? $with(['after' => $nextAfter]) : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        $link = static fn (string $description) => StringSchema::create(description: $description, examples: ['https://example.com/api/cr/default/workspaces/live/events?after=1234&limit=25']);
        return $schema ??= ObjectSchema::create(
            description: 'The URLs of this page and the next, the request with another cursor',
            properties: ObjectProperties::create(
                self: $link('This page'),
                next: Nullable::wrap($link('The page after, null on the last one')),
            ),
            additionalProperties: false,
            required: ['self', 'next'],
        );
    }
}
