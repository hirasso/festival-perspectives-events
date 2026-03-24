<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Exception;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Psr\Log\LoggerInterface;

/**
 * Deletes data that is not required anymore
 *
 * – deletes expired recurrences
 */
final class Archiver
{
    public function __construct(
        private Core $core,
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
     * Uses a raw SQL query to make sure all candidates are found.
     */
    private function deleteAllExpiredRecurrences(): int
    {
        $wpdb = $this->core->utils->wpdb();

        /** @var list<int> $expiredIds */
        $expiredIds = collect($wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND pm.meta_key = %s
               AND CAST(pm.meta_value AS DATE) < %s",
            PostTypes::RECURRENCE,
            EventFields::DATE_AND_TIME,
            current_time('Y') . '-01-01',
        )))->map(absint(...))->all();

        foreach ($expiredIds as $id) {
            wp_delete_post($id, true);
        }

        return count($expiredIds);
    }
}
