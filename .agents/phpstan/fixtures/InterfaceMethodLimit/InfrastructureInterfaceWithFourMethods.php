<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence;

interface InfrastructureInterfaceWithFourMethods
{
    public function first(): void;

    public function second(): void;

    public function third(): void;

    public function fourth(): void;
}
