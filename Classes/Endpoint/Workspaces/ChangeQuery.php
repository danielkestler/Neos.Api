<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Parameter\ChangeTypes;
use Neos\Api\Endpoint\Workspaces\Schema\Change;
use Neos\Api\Endpoint\Workspaces\Schema\ChangeList;
use Neos\Api\Endpoint\Workspaces\Schema\PaginatedChangeListing;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\Neos\PendingChangesProjection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The query of listWorkspaceChanges: filters, include, offset and limit applied to the pending changes
 *
 * The projection reads all changes at once, has no order and can't check permissions or filter, so all of that is done
 * here in PHP: the changes of nodes the account may not read are left out, the others located (ChangeLocator, cached,
 * most changes share a few documents), filtered, sorted by document, node and dimension space point, so the changes
 * of one document are together, and paged. The diff is computed for the page only. Only Schema\Change and
 * ChangeDiffer read the projection's changes
 */
final readonly class ChangeQuery
{
    private function __construct(
        private NodeAggregateId|null $document,
        private NodeAggregateId|null $site,
        private ChangeTypes|null $changeTypes,
        private bool $withDiff,
        private Offset $offset,
        private Limit $limit,
    ) {
    }

    /**
     * The query, a 400 for an include other than diff
     */
    public static function create(NodeAggregateId|null $filterByDocument, NodeAggregateId|null $filterBySite, ChangeTypes|null $filterByChangeType, IncludePaths $include, Offset $offset, Limit $limit): self|BadRequest
    {
        $unsupported = $include->unsupported(['diff']);
        if ($unsupported !== null) {
            return $unsupported;
        }
        return new self($filterByDocument, $filterBySite, $filterByChangeType, $include->includes('diff'), $offset, $limit);
    }

    /**
     * @param iterable<PendingChangesProjection\Change> $pendingChanges the workspace's
     */
    public function page(ServerRequestInterface $request, iterable $pendingChanges, ReadableWorkspace $workspace, ChangeDiffer $changeDiffer): PaginatedChangeListing
    {
        $locator = new ChangeLocator($workspace);
        $changes = [];
        foreach ($pendingChanges as $pendingChange) {
            if (!$workspace->mayReadNodeAggregate($pendingChange->nodeAggregateId)) {
                continue;
            }
            // located for every change: the filters and the order need the documents
            $change = Change::from($pendingChange, $locator);
            if (
                ($this->changeTypes !== null && !$this->changeTypes->matches($change))
                || ($this->document !== null && $change->documentAggregateId?->value !== $this->document->value)
                || ($this->site !== null && $change->siteAggregateId?->value !== $this->site->value)
            ) {
                continue;
            }
            $changes[] = [$change, $pendingChange];
        }
        usort($changes, static fn (array $a, array $b) => self::sortKey($a[0]) <=> self::sortKey($b[0]));
        $page = [];
        foreach (array_slice($changes, $this->offset->value, $this->limit->value) as [$change, $pendingChange]) {
            $page[] = $this->withDiff ? $change->withDiff($changeDiffer->diff($workspace, $pendingChange)) : $change;
        }
        return new PaginatedChangeListing(
            new ChangeList(...$page),
            new ListingMeta(count($changes)),
            ListingLinks::for($request, $this->offset, $this->limit, count($changes)),
        );
    }

    /**
     * The document, then the node and its dimension space point (aggregate-wide changes first); changes without a
     * document last
     *
     * @return array{bool, string, string, string}
     */
    private static function sortKey(Change $change): array
    {
        return [
            $change->documentAggregateId === null,
            $change->documentAggregateId->value ?? '',
            $change->nodeAggregateId->value,
            $change->originDimensionSpacePoint->value ?? '',
        ];
    }
}
