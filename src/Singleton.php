<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

/** @phpstan-consistent-constructor */
abstract class Singleton
{
    protected static array $instances = [];

    private function __clone() {}

    protected function __construct() {}

    final public static function instance(): static
    {
        return static::$instances[static::class] ??= new static();
    }
}
