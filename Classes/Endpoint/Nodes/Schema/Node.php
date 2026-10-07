<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * A node with its properties and, if included, its references, children and variants
 *
 * The schema is written out: properties and references are maps, which schematic can't discover from an array, and
 * children and variants contain Node itself, see NodeList
 */
final readonly class Node implements ProvidesSchema
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, list<array{aggregateId: string, properties: array<string, mixed>|\stdClass}>>|\stdClass|null $references
     * @param NodeList|null $children typed iterable, not NodeList, see there
     * @param NodeList|null $variants as $children
     */
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public WorkspaceName $workspaceName,
        public DimensionSpacePoint $dimensionSpacePoint,
        public NodeAggregateId $aggregateId,
        public NodeName|null $name,
        public NodeTypeName $nodeType,
        public string $label,
        public NodeClassification $classification,
        public bool $isHidden,
        public bool $isHiddenByAncestor,
        public bool $isShineThrough,
        public NodeTimestamps $timestamps,
        public array $properties,
        public array|\stdClass|null $references,
        public iterable|null $children,
        public iterable|null $variants,
    ) {
    }

    public static function from(ContentGraph\Node $node, NodeSerializer $nodeSerializer, bool $includeReferences, NodeList|null $children = null, NodeList|null $variants = null): self
    {
        return new self(
            ContentRepositoryId::fromString($node->contentRepositoryId->value),
            WorkspaceName::fromString($node->workspaceName->value),
            DimensionSpacePoint::from($node->dimensionSpacePoint),
            NodeAggregateId::fromString($node->aggregateId->value),
            $node->name !== null ? NodeName::fromString($node->name->value) : null,
            NodeTypeName::fromString($node->nodeTypeName->value),
            $nodeSerializer->label($node),
            NodeClassification::from($node->classification->value),
            $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()),
            $node->tags->onlyInherited()->contain(NeosSubtreeTag::disabled()),
            !$node->dimensionSpacePoint->equals($node->originDimensionSpacePoint),
            NodeTimestamps::from($node->timestamps),
            $nodeSerializer->properties($node->properties),
            $includeReferences ? self::references($nodeSerializer->references($node), $nodeSerializer) : null,
            $children,
            $variants,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A node in a workspace and dimension space point',
            properties: ObjectProperties::create(
                contentRepositoryId: ContentRepositoryId::schema(),
                workspaceName: WorkspaceName::schema(),
                // the point the node was read in, see isShineThrough
                dimensionSpacePoint: DimensionSpacePoint::schema(),
                aggregateId: NodeAggregateId::schema(),
                name: Nullable::wrap(NodeName::schema()),
                nodeType: NodeTypeName::schema(),
                label: StringSchema::create(description: 'The label as plain text, as the Neos backend shows it'),
                classification: NodeClassification::schema(),
                isHidden: BooleanSchema::create(description: 'Whether the node itself is hidden'),
                isHiddenByAncestor: BooleanSchema::create(description: 'Whether the node is inside a hidden node, which hides it as well'),
                isShineThrough: BooleanSchema::create(description: 'Whether the node is shown with the content of another dimension space point (a fallback), e.g. en_UK showing en_US'),
                timestamps: NodeTimestamps::schema(),
                properties: ObjectSchema::create(
                    description: 'The properties in their serialized form, as the content repository stores them, e.g. a date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone',
                    examples: [['title' => 'Home', 'image' => ['id' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3', 'url' => 'https://example.com/_Resources/Persistent/…/image.jpg']]],
                    additionalProperties: true,
                ),
                references: Nullable::wrap(ObjectSchema::create(
                    description: 'Only if included (e.g. include=references or children.references), else null: the references the node type declares, each with the referenced nodes in their order (an empty list if there are none) as {"aggregateId": "…", "properties": {…}}, the properties of the reference serialized as the node\'s',
                    examples: [['relatedPages' => [['aggregateId' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3', 'properties' => new \stdClass()]]]],
                    additionalProperties: true,
                )),
                // the very schema of NodeList, a copy with another description would be no branch the serializer knows
                children: Nullable::wrap(NodeList::schema()),
                variants: Nullable::wrap(NodeList::schema()),
            ),
            additionalProperties: false,
            required: ['contentRepositoryId', 'workspaceName', 'dimensionSpacePoint', 'aggregateId', 'name', 'nodeType', 'label', 'classification', 'isHidden', 'isHiddenByAncestor', 'isShineThrough', 'timestamps', 'properties', 'references', 'children', 'variants'],
        );
    }

    /**
     * Objects where they are empty: the serializer can't tell an empty map from an empty list, but keeps an empty
     * stdClass as {}
     *
     * @param array<string, list<ContentGraph\Reference>> $references
     * @return array<string, list<array{aggregateId: string, properties: array<string, mixed>|\stdClass}>>|\stdClass
     */
    private static function references(array $references, NodeSerializer $nodeSerializer): array|\stdClass
    {
        if ($references === []) {
            return new \stdClass();
        }
        return array_map(static fn (array $named) => array_map(static function (ContentGraph\Reference $reference) use ($nodeSerializer) {
            $properties = $reference->properties !== null ? $nodeSerializer->properties($reference->properties) : [];
            return [
                'aggregateId' => $reference->node->aggregateId->value,
                'properties' => $properties === [] ? new \stdClass() : $properties,
            ];
        }, $named), $references);
    }
}
