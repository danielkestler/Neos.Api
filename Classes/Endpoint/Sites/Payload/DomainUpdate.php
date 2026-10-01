<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Payload;

use Neos\Api\Endpoint\Sites\Schema\Hostname;
use Neos\Api\Endpoint\Sites\Schema\Port;
use Neos\Api\Endpoint\Sites\Schema\UriScheme;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a domain: what is left out or null stays as it is
 */
final readonly class DomainUpdate implements ProvidesSchema
{
    /**
     * @param Hostname|null $hostname unique among all domains
     */
    public function __construct(
        public Hostname|null $hostname = null,
        public UriScheme|null $scheme = null,
        public Port|null $port = null,
        public bool|null $active = null,
    ) {
    }

    public function applyTo(Model\Domain $domain): void
    {
        if ($this->hostname !== null) {
            $domain->setHostname($this->hostname->value);
        }
        if ($this->scheme !== null) {
            $domain->setScheme($this->scheme->value);
        }
        if ($this->port !== null) {
            $domain->setPort($this->port->value);
        }
        if ($this->active !== null) {
            $domain->setActive($this->active);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
