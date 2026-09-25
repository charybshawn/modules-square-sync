<?php

namespace Cultpantry\SquareSync\Contracts\Null;

use Cultpantry\SquareSync\Contracts\AdminAlerts;

class NullAdminAlerts implements AdminAlerts
{
    public function send(string $title, array $lines, string $level, string $url): void {}
}
