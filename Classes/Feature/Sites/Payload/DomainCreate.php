<?php
declare(strict_types=1);

namespace Neos\Api\Feature\Sites\Payload;

use Neos\Api\Feature\Sites\Schema\Hostname;
use Neos\Api\Feature\Sites\Schema\Port;
use Neos\Api\Feature\Sites\Schema\UriScheme;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\Domain as NeosDomain;
use Neos\Neos\Domain\Model\Site as NeosSite;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A domain to add to a site
 */
final readonly class DomainCreate implements ProvidesSchema
{
    /**
     * @param Hostname $hostname unique among all domains
     * @param UriScheme|null $scheme left out, the domain works with the scheme of the request
     * @param Port|null $port left out, the domain works with the port of the request
     * @param bool|null $active true if left out
     */
    public function __construct(
        public Hostname $hostname,
        public UriScheme|null $scheme = null,
        public Port|null $port = null,
        public bool|null $active = null,
    ) {
    }

    /**
     * The domain, added to the site's domains
     */
    public function toNeosDomain(NeosSite $site): NeosDomain
    {
        $domain = new NeosDomain();
        $domain->setHostname($this->hostname->value);
        $domain->setScheme($this->scheme?->value);
        $domain->setPort($this->port?->value);
        $domain->setActive($this->active ?? true);
        $domain->setSite($site);
        // the inverse side, which Site::getPrimaryDomain() falls back to
        $site->getDomains()->add($domain);
        return $domain;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
