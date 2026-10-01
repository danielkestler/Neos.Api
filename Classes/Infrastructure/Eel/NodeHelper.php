<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Eel;

use Neos\Api\Infrastructure\I18n\LabelTranslator;
use Neos\Api\Shared\Schema\AcceptLanguage;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Service\IconNameMappingService;

/**
 * Neos.Api.Node in Fusion: what views need about a node beyond Neos.Node, e.g. for trees of an editing UI
 *
 * Eel creates helpers with `new`, so the dependencies are injected properties, not constructor arguments (and the
 * class can't be readonly, Flow can't proxy that)
 */
final class NodeHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    #[Flow\Inject]
    protected IconNameMappingService $iconNameMappingService;

    #[Flow\Inject]
    protected LabelTranslator $labelTranslator;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * The child nodes of the node types in their order, unlike FlowQuery's children() with several filters, which
     * returns them grouped by filter
     *
     * @param string $nodeTypes a node type filter, e.g. "Neos.Neos:Content,Neos.Neos:ContentCollection" or "!Neos.Neos:Document"
     * @return list<Node>
     */
    public function childNodes(Node $node, string $nodeTypes): array
    {
        return iterator_to_array($this->contentRepositoryRegistry->subgraphForNode($node)->findChildNodes(
            $node->aggregateId,
            FindChildNodesFilter::create(nodeTypes: $nodeTypes),
        ), false);
    }

    /**
     * Whether the node has child nodes of the node types, without loading them
     *
     * @param string $nodeTypes a node type filter as for childNodes()
     */
    public function hasChildNodes(Node $node, string $nodeTypes): bool
    {
        return $this->contentRepositoryRegistry->subgraphForNode($node)->countChildNodes(
            $node->aggregateId,
            CountChildNodesFilter::create(nodeTypes: $nodeTypes),
        ) > 0;
    }

    /**
     * The properties in their serialized form, as the content repository stores them, so they are JSON-safe: e.g. a
     * date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone.
     * An object, so a node without properties encodes as {}, not []
     */
    public function properties(Node $node): \stdClass
    {
        $properties = [];
        foreach ($node->properties->serialized()->getPlainValues() as $name => $value) {
            // only arrays can be serialized assets, the others need no deserializing
            $properties[$name] = is_array($value) ? $this->assetsIn($node->getProperty($name), $value) : $value;
        }
        return (object)$properties;
    }

    /**
     * The references the node type declares, each with the aggregate ids of the referenced nodes in their order,
     * an empty list if there are none. An object, so a node without references encodes as {}, not []
     */
    public function references(Node $node): \stdClass
    {
        $references = array_fill_keys(array_keys($this->nodeType($node)?->getReferences() ?? []), []);
        foreach ($this->contentRepositoryRegistry->subgraphForNode($node)->findReferences($node->aggregateId, FindReferencesFilter::create()) as $reference) {
            $references[$reference->name->value][] = $reference->node->aggregateId->value;
        }
        return (object)$references;
    }

    /**
     * The label as plain text: the label generator may return markup, e.g. of a text property
     */
    public function label(Node $node): string
    {
        return trim(html_entity_decode(strip_tags($this->nodeLabelGenerator->getLabel($node)), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Whether the node itself is hidden, not just inside a hidden node
     */
    public function isHidden(Node $node): bool
    {
        return $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled());
    }

    /**
     * Whether the node is inside a hidden node, which hides it as well
     */
    public function isHiddenByAncestor(Node $node): bool
    {
        return $node->tags->onlyInherited()->contain(NeosSubtreeTag::disabled());
    }

    /**
     * Whether the node is removed (soft-deleted), itself or inside a removed node. Neos never shows removed nodes,
     * so this is false for every node a view finds, unless its subgraph includes removed nodes
     */
    public function isRemoved(Node $node): bool
    {
        return $node->tags->contain(NeosSubtreeTag::removed());
    }

    /**
     * Whether the node is shown in its dimension space point with the content of another one (a fallback), e.g. en_UK
     * showing en_US
     */
    public function isShineThrough(Node $node): bool
    {
        return !$node->dimensionSpacePoint->equals($node->originDimensionSpacePoint);
    }

    /**
     * @return array{created: string, lastModified: string|null, originalCreated: string, originalLastModified: string|null} as ISO 8601, the original ones are of the live workspace
     */
    public function timestamps(Node $node): array
    {
        return [
            'created' => $node->timestamps->created->format(\DateTimeInterface::ATOM),
            'lastModified' => $node->timestamps->lastModified?->format(\DateTimeInterface::ATOM),
            'originalCreated' => $node->timestamps->originalCreated->format(\DateTimeInterface::ATOM),
            'originalLastModified' => $node->timestamps->originalLastModified?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The node type's ui.icon as a Font Awesome class, legacy "icon-*" names converted as in the Neos UI
     */
    public function nodeTypeIcon(Node $node): ?string
    {
        $icon = $this->nodeType($node)?->getConfiguration('ui.icon');
        return is_string($icon) && $icon !== '' ? $this->iconNameMappingService->convert($icon) : null;
    }

    /**
     * The node type's ui.label, translated to the Accept-Language of the request as all labels of the API
     *
     * @param ActionRequest $request the Fusion global request
     */
    public function nodeTypeLabel(Node $node, ActionRequest $request): ?string
    {
        $acceptLanguage = $request->getHttpRequest()->getHeaderLine('Accept-Language');
        return $this->labelTranslator
            ->forAcceptLanguage($acceptLanguage !== '' ? AcceptLanguage::fromString($acceptLanguage) : null)
            ->label($this->nodeType($node)?->getLabel());
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
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

    private function nodeType(Node $node): ?NodeType
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
    }
}
