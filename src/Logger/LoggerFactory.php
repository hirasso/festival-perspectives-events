<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Logger;

use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\Logger;

use function Env\env;

class LoggerFactory
{
    /**
     * Create a Monolog\Logger instance
     */
    public static function create(
        string $name,
        bool $isWpCli,
        bool $sendEmails = true,
        bool $throwOnCritical = true,
    ): Logger {

        $logger = new Logger($name);
        $logger->setTimezone(wp_timezone());

        /** Needs to be pushed first so that it gets called last */
        if ($throwOnCritical) {
            $logger->pushHandler(new ThrowOnCriticalHandler());
        }

        /**
         * Handle PHP errors with Monolog
         * @see https://stackoverflow.com/a/36744961/586823
         */
        $error_handler = new ErrorHandler($logger);
        $error_handler->registerErrorHandler([], false);
        $error_handler->registerExceptionHandler();
        $error_handler->registerFatalHandler();

        if ($sendEmails) {
            $logger->pushHandler(
                new WPMailHandler(
                    self::getMailTo(),
                    self::getMailFrom(),
                ),
            );
        }

        /** Add WP_CLI messages if in WP CLI */
        if ($isWpCli) {
            $wpCLIHandler = new WPCLIHandler(Level::Debug);
            $wpCLIHandler->setFormatter(new LineFormatter("%message% %context%"));
            $logger->pushHandler($wpCLIHandler);
        }

        return $logger;
    }

    /**
     * Get the recipients for sending emails
     * @return list<string>
     */
    private static function getMailTo(): array
    {
        $to = trim(env('LOGGER_MAIL_TO') ?: '');

        return $to !== ''
            ? array_map(trim(...), explode(',', $to))
            : [get_option('admin_email')];
    }

    /**
     * Get the sender address for sending emails
     */
    private static function getMailFrom(): string
    {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        $default = 'logger@' . preg_replace('#^www\.#', '', $host);

        return trim(env('LOGGER_MAIL_FROM') ?: $default);
    }
}
