<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Logger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use WP_CLI;

final class WPCLIHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        $message = $this->format($record);

        // Prepend level name for notice and above
        if ($record->level->isHigherThan(Level::Info)) {
            $levelName = $record->level->getName();
            $message = "($levelName) $message";
        }

        match (true) {
            $record->level->isHigherThan(Level::Warning) => WP_CLI::error($message, false),
            $record->level === Level::Warning,
            $record->level === Level::Notice => WP_CLI::warning($message),
            $record->level === Level::Info => WP_CLI::log($message),
            default => WP_CLI::debug($message),
        };
    }

    private function format(LogRecord $record): string
    {
        $message = $record->formatted ?? $record->message;

        if (empty($record->context)) {
            $message = str_replace(' []', '', $message);
        }

        return $message;
    }
}
