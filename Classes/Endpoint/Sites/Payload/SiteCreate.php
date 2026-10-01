<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Payload;

use Neos\Api\Endpoint\Sites\Schema\NodeTypeName;
use Neos\Api\Endpoint\Sites\Schema\PackageKey;
use Neos\Api\Endpoint\Sites\Schema\SiteName;
use Neos\Api\Endpoint\Sites\Schema\SiteNodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos site to create, with a new site node in the live workspace
 */
final readonly class SiteCreate implements ProvidesSchema
{
    /**
     * @param PackageKey $packageKey an installed site package, see getSiteCreationOptions
     * @param NodeTypeName $nodeTypeName the type of the site node, a non-abstract subtype of Neos.Neos:Site, see getSiteCreationOptions
     * @param SiteNodeName|null $nodeName derived from the name if left out
     * @param bool|null $online true if left out
     */
    public function __construct(
        public PackageKey $packageKey,
        public SiteName $name,
        public NodeTypeName $nodeTypeName,
        public SiteNodeName|null $nodeName = null,
        public bool|null $online = null,
    ) {
    }

    /**
     * The node name the site gets, the way SiteService::createSite() derives it
     */
    public function siteNodeName(): SiteNodeName
    {
        return $this->nodeName ?? SiteNodeName::fromString(NodeName::transliterateFromString($this->name->value)->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
