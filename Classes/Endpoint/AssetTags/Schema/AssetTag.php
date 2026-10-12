<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * A tag of the media library with its child tags, tags form a tree
 *
 * children contains AssetTag itself, see AssetTagList: so the schema is written out
 */
final readonly class AssetTag implements ProvidesSchema
{
    /**
     * @param AssetTagId|null $parentId the parent tag, null for a tag at the root
     * @param int $assetCount how many assets have the tag
     * @param AssetTagList $children typed iterable, not AssetTagList, see there
     */
    public function __construct(
        public AssetTagId $id,
        public string $label,
        public AssetTagId|null $parentId,
        public int $assetCount,
        public iterable $children,
    ) {
    }

    public static function from(Model\Tag $tag, PersistenceManagerInterface $persistenceManager, AssetRepository $assetRepository): self
    {
        $parent = $tag->getParent();
        $children = array_values($tag->getChildren()->toArray());
        usort($children, static fn (Model\Tag $a, Model\Tag $b) => strcasecmp($a->getLabel(), $b->getLabel()));
        return new self(
            AssetTagId::fromString($persistenceManager->getIdentifierByObject($tag)),
            $tag->getLabel(),
            $parent !== null ? AssetTagId::fromString($persistenceManager->getIdentifierByObject($parent)) : null,
            $assetRepository->countByTag($tag),
            new AssetTagList(...array_map(
                static fn (Model\Tag $child) => self::from($child, $persistenceManager, $assetRepository),
                $children,
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A tag of the media library with its child tags',
            properties: ObjectProperties::create(
                id: AssetTagId::schema(),
                label: AssetTagLabel::schema(),
                parentId: Nullable::wrap(AssetTagId::schema()),
                assetCount: IntegerSchema::create(description: 'How many assets have the tag', minimum: 0),
                // the very schema of AssetTagList, see NodeList
                children: AssetTagList::schema(),
            ),
            additionalProperties: false,
            required: ['id', 'label', 'parentId', 'assetCount', 'children'],
        );
    }
}
