<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

interface LeanApplicationPort
{
    public function first(): void;

    public function second(): void;

    public function third(): void;
}
