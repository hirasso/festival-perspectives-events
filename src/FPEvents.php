<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use DateTimeImmutable;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\LocationFields;
use WP_Post;

/**
 * Manage events, recurrences and locations using Advanced Custom Fields
 */
final class FPEvents extends Singleton
{
    public Core $core;
    public Recurrences $recurrences;

    protected function __construct()
    {
        $this->core = Core::instance();
        $this->recurrences = Recurrences::instance();
        Locations::instance();
        EventFields::instance();
        LocationFields::instance();
        PolylangIntegration::instance();
    }

    /**
     * Get an event's date and time, separated by $separator
     */
    public function getEventDateAndTime(int|WP_Post $post, string $separator = ', '): ?string
    {
        if (!$this->core->isEvent($post)) {
            return null;
        }

        $rawDate = get_field(EventFields::DATE_AND_TIME, $post, false);

        $date = date_i18n(get_option('date_format'), strtotime($rawDate));
        $time = date_i18n(get_option('time_format'), strtotime($rawDate));

        return collect([$date, $time])->filter()->join($separator);
    }

    /**
     * Get the year of an event
     */
    public function getEventYear(int|WP_Post $post): ?string
    {
        if (!$this->core->isEvent($post)) {
            return null;
        }

        $rawDate = get_field(EventFields::DATE_AND_TIME, $post, false);

        return (new DateTimeImmutable($rawDate))->format('Y');
    }

    /**
     * Return "Location Name, Location Area"
     */
    public function getLocationNameAndArea(int $eventID): string
    {
        $locationID = get_field(EventFields::LOCATION_ID, $eventID);

        return collect([
            get_the_title($locationID),
            get_field(LocationFields::AREA, $locationID) ?: '',
        ])
        ->filter($this->core->isFilledString(...))
        ->join(', ');
    }
}
