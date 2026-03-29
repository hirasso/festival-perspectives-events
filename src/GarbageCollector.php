<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Deletes data that is not required anymore
 *
 * – deletes expired recurrences
 */
final class GarbageCollector
{
    private Utils $utils;

    public function __construct(
        private LoggerInterface $logger,
    ) {
        $this->utils = Utils::instance();
    }

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
            $this->logger->info("Deleting past event recurrences...");
            $deletedCount = $this->deletePastRecurrences();
            $this->success(sprintf('Deleted %d recurrences', $deletedCount));
        } catch (Exception $e) {
            $this->critical($e->getMessage());
        }
    }

    /**
     * Delete expired recurrences. Return the count.
     */
    private function deletePastRecurrences(): int
    {
        $recurrences = $this->utils->getPastRecurrences();

        foreach ($recurrences as $postID) {
            if (get_post_type($postID) !== PostTypes::RECURRENCE) {
                throw new RuntimeException(sprintf('Not a recurrence: #%d', (int) $postID));
            }
            wp_delete_post($postID, true);
        }

        return count($recurrences);
    }
}
