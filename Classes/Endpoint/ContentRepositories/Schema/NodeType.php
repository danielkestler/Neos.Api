<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core;
use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Neos\Service\IconNameMappingService;

/**
 * A node type with what trees and creation UIs need and, if requested, its properties, references and whole
 * configuration
 *
 * The schema is written out: position is an integer or a string, and the configuration a map, which schematic can't
 * discover
 */
final readonly class NodeType implements ProvidesSchema
{
    /**
     * The keys whose string values in the configuration are labels, as the Neos UI translates them: ui.label,
     * ui.help.message, editorOptions.placeholder, and the labels of inspector tabs, groups and views, select box values
     * and creation dialog elements
     */
    private const array LABEL_KEYS = ['label', 'message', 'placeholder'];

    /**
     * @param array<string, mixed>|\stdClass|null $configuration
     */
    public function __construct(
        public NodeTypeName $name,
        public string $label,
        public string|null $icon,
        public bool $isAbstract,
        public bool $isFinal,
        public NodeTypeNameList $superTypes,
        public string|null $group,
        public int|string|null $position,
        public NodeTypePropertyList|null $properties,
        public NodeTypeReferenceList|null $references,
        public array|\stdClass|null $configuration,
    ) {
    }

    public static function from(
        Core\NodeType\NodeType $nodeType,
        Labels $labels,
        IconNameMappingService $iconNameMappingService,
        bool $withProperties,
        bool $withReferences,
        bool $withConfiguration,
    ): self {
        $group = $nodeType->getConfiguration('ui.group');
        $position = $nodeType->getConfiguration('ui.position');
        $configuration = self::translateLabels($nodeType->getFullConfiguration(), $labels);
        return new self(
            NodeTypeName::fromString($nodeType->name->value),
            $labels->label($nodeType->getLabel()) ?? $nodeType->name->value,
            self::icon($nodeType->getConfiguration('ui.icon'), $iconNameMappingService),
            $nodeType->isAbstract(),
            $nodeType->isFinal(),
            new NodeTypeNameList(...array_map(
                static fn (string $name) => NodeTypeName::fromString($name),
                array_keys($nodeType->getDeclaredSuperTypes()),
            )),
            is_string($group) && $group !== '' ? $group : null,
            is_int($position) || is_string($position) ? $position : null,
            $withProperties ? self::properties($nodeType, $labels) : null,
            $withReferences ? self::references($nodeType, $labels) : null,
            // an object where it is empty: the serializer can't tell an empty map from an empty list
            $withConfiguration ? ($configuration === [] ? new \stdClass() : $configuration) : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A node type of a content repository',
            properties: ObjectProperties::create(
                name: NodeTypeName::schema(),
                label: StringSchema::create(description: 'The label (ui.label) in the requested language, the name if it has none'),
                icon: Nullable::wrap(StringSchema::create(
                    description: 'The icon (ui.icon) as Font Awesome classes, legacy names like "icon-file" or "file" mapped as the Neos UI does',
                    examples: ['fas fa-file'],
                )),
                isAbstract: BooleanSchema::create(description: 'Whether the node type only serves as a super type, nodes can\'t have it'),
                isFinal: BooleanSchema::create(description: 'Whether no other node type may inherit from it'),
                superTypes: NodeTypeNameList::schema(),
                group: Nullable::wrap(StringSchema::create(
                    description: 'The group (ui.group) the Neos UI offers it in for creation, a key of the Neos.Neos.nodeTypes.groups settings. Node types without a group aren\'t offered',
                    examples: ['general'],
                )),
                position: AnyOfSchema::create(
                    IntegerSchema::create(description: 'The position (ui.position) within its group as a number'),
                    StringSchema::create(description: 'The position (ui.position) within its group as a position string', examples: ['before Neos.Demo:Content.Text', 'end']),
                    NullSchema::create(),
                ),
                properties: Nullable::wrap(NodeTypePropertyList::schema()),
                references: Nullable::wrap(NodeTypeReferenceList::schema()),
                configuration: Nullable::wrap(ObjectSchema::create(
                    description: 'The whole configuration, merged with the super types\', as NodeTypes.yaml declares it. Its labels (label, help message and placeholder values that are translation IDs) are translated to the requested language. In the list only with include=configuration, else null',
                    additionalProperties: true,
                )),
            ),
            additionalProperties: false,
            required: ['name', 'label', 'icon', 'isAbstract', 'isFinal', 'superTypes', 'group', 'position', 'properties', 'references', 'configuration'],
        );
    }

    private static function properties(Core\NodeType\NodeType $nodeType, Labels $labels): NodeTypePropertyList
    {
        $properties = [];
        foreach ($nodeType->getProperties() as $name => $configuration) {
            // internal plumbing like _hidden, not content
            if (str_starts_with((string)$name, '_')) {
                continue;
            }
            $properties[] = NodeTypeProperty::from((string)$name, is_array($configuration) ? $configuration : [], $labels);
        }
        return new NodeTypePropertyList(...$properties);
    }

    private static function references(Core\NodeType\NodeType $nodeType, Labels $labels): NodeTypeReferenceList
    {
        $references = [];
        foreach ($nodeType->getReferences() as $name => $configuration) {
            $references[] = NodeTypeReference::from((string)$name, is_array($configuration) ? $configuration : [], $labels);
        }
        return new NodeTypeReferenceList(...$references);
    }

    /**
     * @param array<mixed> $configuration
     * @return array<mixed>
     */
    private static function translateLabels(array $configuration, Labels $labels): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $configuration[$key] = self::translateLabels($value, $labels);
            } elseif (is_string($value) && in_array($key, self::LABEL_KEYS, true)) {
                $configuration[$key] = $labels->label($value) ?? $value;
            }
        }
        return $configuration;
    }

    /**
     * Node types configure anything from bare legacy names ("file") over "icon-file" to full classes ("fas fa-file").
     * IconNameMappingService only maps "icon-" names, so bare ones get the prefix first. Full classes (with a space)
     * are kept
     */
    private static function icon(mixed $icon, IconNameMappingService $iconNameMappingService): string|null
    {
        if (!is_string($icon) || $icon === '') {
            return null;
        }
        if (str_contains($icon, ' ')) {
            return $icon;
        }
        return $iconNameMappingService->convert(str_starts_with($icon, 'icon-') ? $icon : 'icon-' . $icon);
    }
}
