<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

final class GroupByMetaClause
{
    public function __construct(
        public string $key,
        public ?string $expression = null,
        public ?string $groupby = null,
    ) {
        $this->groupby ??= $key;
        $this->expression ??= "{alias}.meta_value as $key";
    }
}
