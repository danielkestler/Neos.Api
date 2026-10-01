<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * What the API tells about a node beyond its own fields, the same for the nodes endpoint and the views
 * (Neos.Api.Node): its label, properties and references as JSON-safe values
 */
#[Flow\Scope('singleton')]
final readonly class NodeSerializer
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private NodeLabelGeneratorInterface $nodeLabelGenerator,
        private ResourceManager $resourceManager,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    /**
     * The label as plain text: the label generator may return markup, e.g. of a text property
     */
    public function label(Node $node): string
    {
        return trim(html_entity_decode(strip_tags($this->nodeLabelGenerator->getLabel($node)), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * The properties in their serialized form, as the content repository stores them, so they are JSON-safe: e.g. a
     * date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone
     *
     * @return array<string, mixed>
     */
    public function properties(PropertyCollection $properties): array
    {
        $values = [];
        foreach ($properties->serialized()->getPlainValues() as $name => $value) {
            // only arrays can be serialized assets, the others need no deserializing
            $values[$name] = is_array($value) ? $this->assetsIn($properties[$name], $value) : $value;
        }
        return $values;
    }

    /**
     * The references the node type declares, each with the references in their order, an empty list if there are none
     *
     * @return array<string, list<Reference>>
     */
    public function references(Node $node): array
    {
        $references = array_fill_keys(array_keys($this->nodeType($node)?->getReferences() ?? []), []);
        foreach ($this->contentRepositoryRegistry->subgraphForNode($node)->findReferences($node->aggregateId, FindReferencesFilter::create()) as $reference) {
            $references[$reference->name->value][] = $reference;
        }
        return $references;
    }

    private function nodeType(Node $node): ?NodeType
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
    }

    /**
     * @param mixed $value the deserialized property value
     * @param array<mixed> $serialized the serialized one, returned if the value holds no asset
     * @return array<mixed>|null
     */
    private function assetsIn(mixed $value, array $serialized): ?array
    {
        if ($value instanceof AssetInterface) {
            return $this->asset($value);
        }
        if (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $item) => !$item instanceof AssetInterface) === []) {
            return array_map($this->asset(...), $value);
        }
        // a single asset that is gone deserializes to null
        return $value === null && isset($serialized['__flow_object_type']) && is_a($serialized['__flow_object_type'], AssetInterface::class, true) ? null : $serialized;
    }

    /**
     * @return array{id: string, url: string|null}
     */
    private function asset(AssetInterface $asset): array
    {
        $resource = $asset->getResource();
        $url = $resource !== null ? $this->resourceManager->getPublicPersistentResourceUri($resource) : null;
        return [
            'id' => $this->persistenceManager->getIdentifierByObject($asset),
            'url' => is_string($url) ? $url : null,
        ];
    }
}
