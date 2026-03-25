<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

/**
 * Make the custom post types available globally
 */
final readonly class PostTypes
{
    public const EVENT = 'acfe-event';
    public const RECURRENCE = 'acfe-recurrence';
    public const LOCATION = 'acfe-location';

    public static function all()
    {
        return [self::EVENT, self::RECURRENCE, self::LOCATION];
    }
}
