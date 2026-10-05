<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

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
 * A node type with what trees and creation UIs need and, if requested, its whole configuration
 *
 * The schema is written out: position is an integer or a string, and the configuration a map, which schematic can't
 * discover
 */
final readonly class NodeType implements ProvidesSchema
{
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
        public array|\stdClass|null $configuration,
    ) {
    }

    public static function from(Core\NodeType\NodeType $nodeType, Labels $labels, IconNameMappingService $iconNameMappingService, bool $withConfiguration): self
    {
        $group = $nodeType->getConfiguration('ui.group');
        $position = $nodeType->getConfiguration('ui.position');
        $configuration = $nodeType->getFullConfiguration();
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
                configuration: Nullable::wrap(ObjectSchema::create(
                    description: 'Only from GET /nodetypes/{nodeTypeName}, else null: the whole configuration, merged with the super types\', as NodeTypes.yaml declares it. Labels in it are not translated',
                    additionalProperties: true,
                )),
            ),
            additionalProperties: false,
            required: ['name', 'label', 'icon', 'isAbstract', 'isFinal', 'superTypes', 'group', 'position', 'configuration'],
        );
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
