<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Repository;

use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;

interface OrderRepository
{
    public function get(OrderId $id): Order;

    public function save(Order $order): void;
}
