<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * Deletes data that is not required anymore
 *
 * – deletes expired recurrences
 */
final class GarbageCollector
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Log a critical event (also exits)
     */
    private function critical(string $message)
    {
        $this->logger->critical("🚨 {$message}");
    }

    /**
     * Log a success
     */
    private function success(string $message): self
    {
        $this->logger->info("✅ {$message}");
        return $this;
    }

    public function run()
    {
        try {
            $this->logger->info("Deleting expired event recurrences...");
            $deletedCount = $this->deleteAllExpiredRecurrences();
            $this->success(sprintf('Deleted %d expired event recurrences', $deletedCount));
        } catch (Exception $e) {
            $this->critical($e->getMessage());
        }
    }

    /**
     * Delete expired recurrences. Return the count.
     */
    private function deleteAllExpiredRecurrences(): int
    {
        $expiredRecurrences = fpe()->getExpiredEvents(PostTypes::RECURRENCE);

        foreach ($expiredRecurrences as $postID) {
            wp_delete_post($postID, true);
        }

        return count($expiredRecurrences);
    }
}
