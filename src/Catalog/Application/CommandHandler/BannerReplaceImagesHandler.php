<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerReplaceImages;
use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Service\BannerPayload;
use App\Catalog\Domain\Model\BannerSet;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R18/R20 — the one command.bus handler that replaces the banner's ordered slide set (CMD-004).
 *
 * The payload shape is validated first (ERR-001), then the aggregate is built, so a 0-image or
 * 11-image selection, a duplicate slide or a reference that is not a dashboard upload throws
 * before a single statement runs: a refused write persists nothing. Everything after that runs
 * inside the one transaction the `doctrine_transaction` middleware opened (CMD-005).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class BannerReplaceImagesHandler
{
    public function __construct(
        private BannerPayload $payload,
        private BannerImageStore $banners,
    ) {
    }

    public function __invoke(BannerReplaceImages $command): void
    {
        $this->banners->replace(BannerSet::fromPaths($this->payload->imagePathsFrom($command->body)));
    }
}
