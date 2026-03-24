<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Logger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

final class ThrowOnCriticalHandler extends AbstractProcessingHandler
{
    public function __construct()
    {
        parent::__construct(Level::Critical);
    }

    protected function write(LogRecord $record): void
    {
        throw new RuntimeException($record->message);
    }
}
