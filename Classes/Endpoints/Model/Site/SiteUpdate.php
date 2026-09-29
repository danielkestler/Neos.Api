<?php
declare(strict_types=1);

namespace Neos\Api\Endpoints\Model\Site;

use Neos\Api\Domain\Site\DomainId;
use Neos\Api\Domain\Site\SiteName;
use Neos\Api\Domain\Site\SiteState;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model\Site as NeosSite;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a Neos site: what is left out or null stays as it is
 */
final readonly class SiteUpdate implements ProvidesSchema
{
    /**
     * @param DomainId|null $primaryDomainId an active domain of the site to become its primary domain
     */
    public function __construct(
        public SiteName|null $name = null,
        public SiteState|null $state = null,
        public DomainId|null $primaryDomainId = null,
    ) {
    }

    /**
     * Applies the name and state, the primary domain is looked up by the caller
     */
    public function applyTo(NeosSite $site): void
    {
        if ($this->name !== null) {
            $site->setName($this->name->value);
        }
        $this->state?->applyTo($site);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
