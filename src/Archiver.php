<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Exception;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\Logger\LoggerFactory;

/**
 * Cleans up data that is not required anymore (e.g. recurrences)
 */
final class Archiver
{
    private \Monolog\Logger $logger;

    public function __construct(private Core $core)
    {
        $this->logger = LoggerFactory::create(
            __CLASS__,
            isWpCli: $core->utils->isWpCli(),
        );
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
            $this->logger->info("Deleting expired event recurrences...");
            $deletedCount = $this->deleteExpiredRecurrences();
            $this->success(sprintf('Deleted %d expired event recurrences', $deletedCount));
        } catch (Exception $e) {
            $this->critical($e->getMessage());
        }

    }

    /**
     * Delete expired recurrences. Return the count.
     */
    private function deleteExpiredRecurrences(): int
    {
        $currentYear = current_time('Y');

        $expiredRecurrences = get_posts([
            'lang' => '',
            'suppress_filters' => true,
            'post_type' => PostTypes::RECURRENCE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key'     => EventFields::DATE_AND_TIME,
                    'value'   => "{$currentYear}-01-01",
                    'compare' => '<',
                    'type'    => 'DATE',
                ],
            ],
            'fields' => 'ids',
        ]);

        return count($expiredRecurrences);
    }
}
