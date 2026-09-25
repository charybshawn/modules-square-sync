<?php

namespace Cultpantry\SquareSync\Contracts;

/**
 * How the module tells the shop's admins that the Square sync broke -- or
 * recovered -- while nobody was looking at the Square Sync page. Only
 * called when the sync's health changes, never on every check, so an
 * implementation can send email without it becoming noise.
 */
interface AdminAlerts
{
    /**
     * @param  array<int, string>  $lines  one problem (or recovery note) per line
     * @param  'warning'|'info'  $level  warning when something broke, info when it recovered
     */
    public function send(string $title, array $lines, string $level, string $url): void;
}
