<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Projection;

use App\Shared\Application\Projection\ProjectionReplayer;

/**
 * The tagged registry behind `projection:replay` (spec-run-15 R30/R31, H6).
 *
 * Each context contributes its replayers through the `app.projection_replayer` tag, so Shared never
 * names a context and adding a replay is one tagged service, not a new branch in a command. Names
 * are unique and sorted so the unknown-name error always lists the same supported set.
 */
final class ProjectionRegistry
{
    /** @var array<string, ProjectionReplayer> */
    private array $replayers = [];

    /**
     * @param iterable<ProjectionReplayer> $replayers
     */
    public function __construct(iterable $replayers)
    {
        foreach ($replayers as $replayer) {
            $name = $replayer->name();
            if (isset($this->replayers[$name])) {
                throw new \LogicException(sprintf('Two projection replayers are registered as "%s".', $name));
            }

            $this->replayers[$name] = $replayer;
        }

        ksort($this->replayers);
    }

    public function find(string $name): ?ProjectionReplayer
    {
        return $this->replayers[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->replayers);
    }
}
