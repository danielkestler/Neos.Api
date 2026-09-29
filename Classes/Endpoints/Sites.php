<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints;

use Neos\Api\Domain\Site\DomainId;
use Neos\Api\Domain\Site\Hostname;
use Neos\Api\Domain\Site\PackageKey;
use Neos\Api\Domain\Site\Site;
use Neos\Api\Domain\Site\SiteNodeName;
use Neos\Api\Domain\Site\Sites as SiteList;
use Neos\Api\Endpoints\Model\Site\DomainCreate;
use Neos\Api\Endpoints\Model\Site\DomainUpdate;
use Neos\Api\Endpoints\Model\Site\PackageKeys;
use Neos\Api\Endpoints\Model\Site\SiteCreate;
use Neos\Api\Endpoints\Model\Site\SiteCreationOptions;
use Neos\Api\Endpoints\Model\Site\SiteNodeType;
use Neos\Api\Endpoints\Model\Site\SiteNodeTypes;
use Neos\Api\Endpoints\Model\Site\SiteUpdate;
use Neos\Api\Endpoints\Response\Conflict;
use Neos\Api\Endpoints\Response\DomainCreated;
use Neos\Api\Endpoints\Response\NotFound;
use Neos\Api\Endpoints\Response\SiteCreated;
use Neos\Api\Endpoints\Response\UnprocessableContent;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain as NeosDomain;
use Neos\Neos\Domain\Model\Site as NeosSite;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\SiteService;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\RequestBody;

/**
 * The Neos sites and their domains
 */
final readonly class Sites
{
    private ContentRepositoryId $contentRepositoryForNewSites;

    public function __construct(
        private SiteRepository $siteRepository,
        private DomainRepository $domainRepository,
        private SiteService $siteService,
        private PackageManager $packageManager,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private PersistenceManagerInterface $persistenceManager,
        #[Flow\InjectConfiguration(path: 'sitePresets.default.contentRepository', package: 'Neos.Neos')]
        string $defaultContentRepository,
    ) {
        // the one the Neos sites module offers the node types of, sites without settings of their own use it as well
        $this->contentRepositoryForNewSites = ContentRepositoryId::fromString($defaultContentRepository);
    }

    #[Operation(
        path: '/sites',
        method: 'GET',
        summary: 'List all sites',
        description: 'All Neos sites, including the offline ones, ordered by name.',
        operationId: 'listSites',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_READ],
        ],
    )]
    public function list(): SiteList
    {
        return new SiteList(...array_map(
            $this->site(...),
            $this->siteRepository->findAll()->toArray(),
        ));
    }

    #[Operation(
        path: '/sites/options',
        method: 'GET',
        summary: 'Get the site creation options',
        description: 'The site packages and site node types a site can be created with.',
        operationId: 'getSiteCreationOptions',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_CREATE],
        ],
    )]
    public function options(): SiteCreationOptions
    {
        $nodeTypeManager = $this->nodeTypeManager($this->contentRepositoryForNewSites);
        return new SiteCreationOptions(
            new PackageKeys(...array_map(
                PackageKey::fromString(...),
                array_keys($this->packageManager->getFilteredPackages('available', 'neos-site')),
            )),
            new SiteNodeTypes(...array_map(
                SiteNodeType::fromNodeType(...),
                array_values($nodeTypeManager->getSubNodeTypes(NodeTypeNameFactory::forSite(), false)),
            )),
        );
    }

    #[Operation(
        path: '/sites',
        method: 'POST',
        summary: 'Create a site',
        description: 'Creates a Neos site with a new site node in the live workspace.',
        operationId: 'createSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_CREATE],
        ],
    )]
    public function create(
        #[RequestBody(description: 'The site')] SiteCreate $newSite,
    ): SiteCreated|Conflict|UnprocessableContent {
        $nodeName = $newSite->siteNodeName();
        if ($this->siteRepository->findOneByNodeName($nodeName->value) !== null) {
            return Conflict::because(sprintf('There is a site with the node name %s already', $nodeName->value));
        }
        if (!$this->packageManager->isPackageAvailable($newSite->packageKey->value)) {
            return UnprocessableContent::because(sprintf('There is no package %s', $newSite->packageKey->value));
        }
        // checked up front: SiteService::createSite() adds the site before it checks the node type
        $unsavedSite = new NeosSite($nodeName->value);
        $nodeType = $this->nodeTypeManager($unsavedSite->getConfiguration()->contentRepositoryId)->getNodeType($newSite->nodeTypeName->value);
        if ($nodeType === null || $nodeType->isAbstract() || !$nodeType->isOfType(NodeTypeNameFactory::NAME_SITE)) {
            return UnprocessableContent::because(sprintf('There is no node type %s that a site node can have', $newSite->nodeTypeName->value));
        }
        $site = $this->siteService->createSite(
            $newSite->packageKey->value,
            $newSite->name->value,
            $newSite->nodeTypeName->value,
            $nodeName->value,
            !($newSite->online ?? true),
        );
        return new SiteCreated($this->site($site));
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'GET',
        summary: 'Get a site',
        operationId: 'getSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_READ],
        ],
    )]
    public function get(SiteNodeName $siteNodeName): Site|NotFound
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        return $site !== null ? $this->site($site) : self::siteNotFound($siteNodeName);
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'PATCH',
        summary: 'Change a site',
        description: 'Changes the given properties and leaves the others as they are.',
        operationId: 'updateSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function update(
        SiteNodeName $siteNodeName,
        #[RequestBody(description: 'The properties to change')] SiteUpdate $patch,
    ): Site|NotFound|UnprocessableContent {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $primaryDomain = null;
        if ($patch->primaryDomainId !== null) {
            $primaryDomain = $this->findDomain($site, $patch->primaryDomainId);
            if ($primaryDomain === null || !$primaryDomain->getActive()) {
                return UnprocessableContent::because(sprintf('The site has no active domain with the ID %s', $patch->primaryDomainId->value));
            }
        }
        $patch->applyTo($site);
        if ($primaryDomain !== null) {
            $site->setPrimaryDomain($primaryDomain);
        }
        $this->siteRepository->update($site);
        return $this->site($site);
    }

    #[Operation(
        path: '/sites/{siteNodeName}',
        method: 'DELETE',
        summary: 'Delete a site',
        description: 'Deletes the site with its domains and its site node in all workspaces, including unpublished changes.',
        operationId: 'deleteSite',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_DELETE],
        ],
    )]
    public function delete(SiteNodeName $siteNodeName): NotFound|null
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $this->siteService->pruneSite($site);
        return null;
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains',
        method: 'POST',
        summary: 'Add a domain to a site',
        operationId: 'createDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function createDomain(
        SiteNodeName $siteNodeName,
        #[RequestBody(description: 'The domain')] DomainCreate $newDomain,
    ): DomainCreated|NotFound|Conflict {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        if ($this->hostnameIsTaken($newDomain->hostname)) {
            return self::hostnameTaken($newDomain->hostname);
        }
        $this->domainRepository->add($newDomain->toNeosDomain($site));
        return new DomainCreated($this->site($site));
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains/{domainId}',
        method: 'PATCH',
        summary: 'Change a domain of a site',
        description: 'Changes the given properties and leaves the others as they are. The body is the site with the domain.',
        operationId: 'updateDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function updateDomain(
        SiteNodeName $siteNodeName,
        DomainId $domainId,
        #[RequestBody(description: 'The properties to change')] DomainUpdate $patch,
    ): Site|NotFound|Conflict {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $domain = $this->findDomain($site, $domainId);
        if ($domain === null) {
            return self::domainNotFound($domainId);
        }
        if ($patch->hostname !== null && $patch->hostname->value !== $domain->getHostname() && $this->hostnameIsTaken($patch->hostname)) {
            return self::hostnameTaken($patch->hostname);
        }
        $patch->applyTo($domain);
        $this->domainRepository->update($domain);
        return $this->site($site);
    }

    #[Operation(
        path: '/sites/{siteNodeName}/domains/{domainId}',
        method: 'DELETE',
        summary: 'Remove a domain from a site',
        description: 'If it was the primary domain, the site is linked with its first active domain. The body is the site without the domain.',
        operationId: 'deleteDomain',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::SITES_UPDATE],
        ],
    )]
    public function deleteDomain(SiteNodeName $siteNodeName, DomainId $domainId): Site|NotFound
    {
        $site = $this->siteRepository->findOneByNodeName($siteNodeName->value);
        if ($site === null) {
            return self::siteNotFound($siteNodeName);
        }
        $domain = $this->findDomain($site, $domainId);
        if ($domain === null) {
            return self::domainNotFound($domainId);
        }
        if ($site->getPrimaryDomain(false) === $domain) {
            $site->setPrimaryDomain(null);
            $this->siteRepository->update($site);
        }
        $site->getDomains()->removeElement($domain);
        $this->domainRepository->remove($domain);
        return $this->site($site);
    }

    private function site(NeosSite $site): Site
    {
        return Site::fromNeosSite($site, $this->persistenceManager);
    }

    private function nodeTypeManager(ContentRepositoryId $contentRepositoryId): NodeTypeManager
    {
        return $this->contentRepositoryRegistry->get($contentRepositoryId)->getNodeTypeManager();
    }

    /**
     * The domain of the site with the ID, null if there is none, or it belongs to another site
     */
    private function findDomain(NeosSite $site, DomainId $domainId): ?NeosDomain
    {
        $domain = $this->domainRepository->findByIdentifier($domainId->value);
        return $domain instanceof NeosDomain && $domain->getSite() === $site ? $domain : null;
    }

    private function hostnameIsTaken(Hostname $hostname): bool
    {
        // not findOneByHost(), which matches subdomains as well
        return $this->domainRepository->findOneByHostname($hostname->value) !== null;
    }

    private static function siteNotFound(SiteNodeName $siteNodeName): NotFound
    {
        return NotFound::because(sprintf('There is no site with the node name %s', $siteNodeName->value));
    }

    private static function domainNotFound(DomainId $domainId): NotFound
    {
        return NotFound::because(sprintf('The site has no domain with the ID %s', $domainId->value));
    }

    private static function hostnameTaken(Hostname $hostname): Conflict
    {
        return Conflict::because(sprintf('There is a domain with the host name %s already', $hostname->value));
    }
}
