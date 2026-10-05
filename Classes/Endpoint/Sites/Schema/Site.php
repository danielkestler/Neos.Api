<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Neos site with its domains
 */
final readonly class Site implements ProvidesSchema
{
    /**
     * @param ContentRepositoryId $contentRepositoryId the content repository with the site's content, from the site's preset
     * @param PackageKey $siteResourcesPackageKey the package with the site's Fusion, templates and resources
     * @param DomainList $domains ordered by host name
     * @param bool $online whether the site is online, offline sites are left out of the backend menu and the fallback when no domain matches
     * @param string|null $primaryDomain the URL of the domain the site is linked with: the primary domain if it is active, else the first active one, null if there is none
     */
    public function __construct(
        public SiteNodeName $nodeName,
        public SiteName $name,
        public bool $online,
        public ContentRepositoryId $contentRepositoryId,
        public PackageKey $siteResourcesPackageKey,
        public DomainList $domains,
        public string|null $primaryDomain,
    ) {
    }

    public static function from(Model\Site $site, PersistenceManagerInterface $persistenceManager): self
    {
        $domains = $site->getDomains()->toArray();
        usort($domains, static fn (Model\Domain $a, Model\Domain $b) => strcmp($a->getHostname(), $b->getHostname()));
        $primaryDomain = $site->getPrimaryDomain();
        return new self(
            SiteNodeName::fromString($site->getNodeName()->value),
            SiteName::fromString($site->getName()),
            $site->isOnline(),
            ContentRepositoryId::fromString($site->getConfiguration()->contentRepositoryId->value),
            PackageKey::fromString($site->getSiteResourcesPackageKey()),
            new DomainList(...array_map(
                static fn (Model\Domain $domain) => Domain::from($domain, $persistenceManager),
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
