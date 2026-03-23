<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

final class GroupedEvents
{
    /** @param \WP_Post[]|null $posts */
    public function __construct(
        public string $title,
        public ?array $posts = [],
    ) {}
}
