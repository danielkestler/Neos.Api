<?php
declare(strict_types=1);

namespace Neos\Api\Domain\Site;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\Domain as NeosDomain;
use Neos\Neos\Domain\Model\Site as NeosSite;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos site with its domains
 */
final readonly class Site implements ProvidesSchema
{
    /**
     * @param PackageKey $siteResourcesPackageKey the package with the site's Fusion, templates and resources
     * @param Domains $domains ordered by host name
     * @param bool $online whether the site is online, offline sites are left out of the backend menu and the fallback when no domain matches
     * @param string|null $primaryDomain the URL of the domain the site is linked with: the primary domain if it is active, else the first active one, null if there is none
     */
    public function __construct(
        public SiteNodeName $nodeName,
        public SiteName $name,
        public bool $online,
        public PackageKey $siteResourcesPackageKey,
        public Domains $domains,
        public string|null $primaryDomain,
    ) {
    }

    public static function fromNeosSite(NeosSite $site, PersistenceManagerInterface $persistenceManager): self
    {
        $domains = $site->getDomains()->toArray();
        usort($domains, static fn (NeosDomain $a, NeosDomain $b) => strcmp($a->getHostname(), $b->getHostname()));
        $primaryDomain = $site->getPrimaryDomain();
        return new self(
            SiteNodeName::fromString($site->getNodeName()->value),
            SiteName::fromString($site->getName()),
            $site->isOnline(),
            PackageKey::fromString($site->getSiteResourcesPackageKey()),
            new Domains(...array_map(
                static fn (NeosDomain $domain) => Domain::fromNeosDomain($domain, $persistenceManager),
                $domains,
            )),
            $primaryDomain !== null ? (string)$primaryDomain : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
