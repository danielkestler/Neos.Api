<?php
declare(strict_types=1);

namespace Neos\Api\Domain\Site;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Neos\Domain\Model\Site as NeosSite;

/**
 * Whether a site is online
 */
enum SiteState: string implements ProvidesSchema
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';

    public static function of(NeosSite $site): self
    {
        return $site->isOnline() ? self::ONLINE : self::OFFLINE;
    }

    public function applyTo(NeosSite $site): void
    {
        $site->setState($this === self::ONLINE ? NeosSite::STATE_ONLINE : NeosSite::STATE_OFFLINE);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Whether a site is online',
            enum: array_column(self::cases(), 'value'),
        );
    }
}
