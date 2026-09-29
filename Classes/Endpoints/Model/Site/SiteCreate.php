<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Model\Site;

use Neos\Api\Domain\Site\NodeTypeName;
use Neos\Api\Domain\Site\PackageKey;
use Neos\Api\Domain\Site\SiteName;
use Neos\Api\Domain\Site\SiteNodeName;
use Neos\Api\Domain\Site\SiteState;
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
     * @param SiteState|null $state online if left out
     */
    public function __construct(
        public PackageKey $packageKey,
        public SiteName $name,
        public NodeTypeName $nodeTypeName,
        public SiteNodeName|null $nodeName = null,
        public SiteState|null $state = null,
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
