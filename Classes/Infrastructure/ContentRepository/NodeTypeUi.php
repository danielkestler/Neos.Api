<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\IconNameMappingService;

/**
 * How a node type shows in an editing UI, the same for the node types endpoint and the views (Neos.Api.Node): its
 * ui.icon and ui.label
 */
#[Flow\Scope('singleton')]
final readonly class NodeTypeUi
{
    public function __construct(
        private IconNameMappingService $iconNameMappingService,
    ) {
    }

    /**
     * The icon as Font Awesome classes, null without one. Node types configure anything from bare legacy names
     * ("file") over "icon-file" to full classes ("fas fa-file"). IconNameMappingService only maps "icon-" names, so
     * bare ones get the prefix first. Full classes (with a space) are kept
     */
    public function icon(NodeType $nodeType): ?string
    {
        $icon = $nodeType->getConfiguration('ui.icon');
        if (!is_string($icon) || $icon === '') {
            return null;
        }
        if (str_contains($icon, ' ')) {
            return $icon;
        }
        return $this->iconNameMappingService->convert(str_starts_with($icon, 'icon-') ? $icon : 'icon-' . $icon);
    }

    /**
     * The label translated, the node type's name if it has none
     */
    public function label(NodeType $nodeType, Labels $labels): string
    {
        return $labels->label($nodeType->getLabel()) ?? $nodeType->name->value;
    }
}
