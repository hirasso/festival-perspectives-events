<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Logger;

use InvalidArgumentException;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Handler to send Emails for Monolog logs using wp_mail
 * @see inspiration https://github.com/NGOjobs-Git/monolog-wp-mail-handler
 */
class WPMailHandler extends AbstractProcessingHandler
{
    /**
     * @param list<string> $to list of mail addresses to send to (https://developer.wordpress.org/reference/functions/wp_mail/)
     * @param string $from mail-address for the "from" entry
     * @param int $throttle how often should emails be sent
     */
    public function __construct(
        private array $to,
        private string $from,
        private int $throttle = HOUR_IN_SECONDS * 12,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        if ($throttle < 0) {
            throw new InvalidArgumentException("throttle needs to be a positive number");
        }
    }

    /**
     * Invoked every time a log is written
     */
    protected function write(LogRecord $record): void
    {
        $throttle_key = 'email_throttle_' . strtolower($record->channel);

        if ($this->throttle <= 0) {
            delete_transient($throttle_key);
        }

        if (get_transient($throttle_key)) {
            return;
        }

        if ($this->throttle > 0) {
            set_transient($throttle_key, true, $this->throttle);
        }

        // Add more context for mails
        $record = $record->with(context: array_merge($record->context, [
            'wp_instance' => ABSPATH,
            'environment' => wp_get_environment_type(),
        ]));

        // easy monolog formatting for mail body
        $htmlFormatter = new HtmlFormatter();
        /** @var string $message */
        $message = $htmlFormatter->format($record);

        $subject = $this->generate_mail_subject($record);

        $mail_sent = $this->send_email($subject, $message);

        if (!$mail_sent) {
            $this->debug_wp_mail();
        }

    }

    /**
     * Sends an email
     */
    public function send_email(string $subject, ?string $message = null): bool
    {
        if (!$message) {
            $message = $subject;
        }
        add_filter('wp_mail_content_type', [$this, 'html_content_type']);
        add_filter('wp_mail_from', [$this, 'wp_mail_from']);
        add_filter('wp_mail_from_name', [$this, 'wp_mail_from_name']);
        $result = wp_mail($this->to, $subject, $message);
        remove_filter('wp_mail_content_type', [$this, 'html_content_type']);
        remove_filter('wp_mail_from', [$this, 'wp_mail_from']);
        remove_filter('wp_mail_from_name', [$this, 'wp_mail_from_name']);
        return $result;
    }

    /**
     * Debug wp_mail
     *
     * @see https://www.bobz.co/debug-wp_mail-function
     */
    private function debug_wp_mail(): void
    {
        global $ts_mail_errors, $phpmailer;

        if (!isset($ts_mail_errors)) {
            $ts_mail_errors = [];
        }

        if (isset($phpmailer)) {
            $ts_mail_errors[] = $phpmailer->ErrorInfo;
        }

        dump($ts_mail_errors);
    }

    /**
     * Generate the email subject from a record
     */
    private function generate_mail_subject(LogRecord $record): string
    {
        return wp_trim_words("{$record->channel}: {$record->level->getName()} '{$record->message}'", 15, "...");
    }

    /**
     * Filter the Mail html_content_type
     */
    public function html_content_type(string $content_type): string
    {
        return 'text/html';
    }

    /**
     * Custom mail from
     */
    public function wp_mail_from(string $from): string
    {
        $from = $this->from ?: $from;
        return $from;
    }

    /**
     * Dynamically overwrite the from name for sending emails
     */
    public static function wp_mail_from_name(string $name): string
    {
        $name = html_entity_decode(get_bloginfo("name"));
        return "$name: Logger";
    }
}
