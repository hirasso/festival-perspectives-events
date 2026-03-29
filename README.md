# Festival Perspectives Events

[![Test Status](https://img.shields.io/github/actions/workflow/status/hirasso/festival-perspectives-events/test.yml?label=tests)](https://github.com/hirasso/festival-perspectives-events/actions/workflows/test.yml)
[![License](https://img.shields.io/github/license/hirasso/festival-perspectives-events.svg?color=brightgreen)](https://github.com/hirasso/festival-perspectives-events/blob/main/LICENSE)

**📆 Open sourced code that powers the program events and locations on the FESTIVAL PERSPECTIVES website. Based on WordPress + Advanced Custom Fields.**

## Demo

**https://www.festival-perspectives.de/programm/**

## Main Features

- Interface for creating events and locations
- Supports grouping results on archive pages: A-Z, by day, by location
- Automatic creation of recurrences based on an ACF repeater field "Further Dates"
- Integrates with the [auto-sync feature of Polylang Pro](https://polylang.pro/documentation/support/guides/working-with-acf-pro/#2-5-synchronize)

## Screenshots

### Frontend:

![Frontend View](https://github.com/user-attachments/assets/fabb405e-fba0-4f4c-8c88-2093fbad2ba3)

### Admin View: Event

![Admin View: Event](https://github.com/user-attachments/assets/6f0b3772-ea5c-4868-a54e-5229ec6704be)

### Admin View: Location

![Admin View: Location](https://github.com/user-attachments/assets/d1b9cfa3-8fc4-4964-9286-58985555f618)

## Installation

This is not a WordPress plugin. Install it directly from GitHub via Composer:

```shell
# add the custom repository to the config:
composer config repositories.festival-perspectives-events vcs https://github.com/hirasso/festival-perspectives-events
# install it
composer require hirasso/festival-perspectives-events
```

Then, in your theme's `functions.php`:

```php
/** require the composer autoloader */
require_once dirname(__DIR__) . '/vendor/autoload.php';
/** initialize the module */
fpe();
/** the same function is also the access point for the API, e.g.: */
fpe()->getEventDateAndTime($postID);
```

## Notes

Notes to future self – NOT a full documentation.

### Scheduled Action 'fpe/run_garbage_collector'

`do_action('fpe/run_garbage_collector')` is automatically scheduled to run once a day.
By default, expired recurrences are deleted during this action.
You can hook into the action to perform your custom archiving jobs:

```php
add_action('fpe/run_garbage_collector', function() {
  foreach (fpe()->utils->getEventsInThePast() as $postID) {
      /** for example: remove filters from expired events */
      removeEventFilters($postID);
  }
});
```

To re-schedule the action to run _now_, you can use `wp cron event unschedule fpe/run_garbage_collector` or a plugin like [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/).

Alternatively, you can invoke the action via WP CLI:

```bash
# triggers the action 'fpe/run_garbage_collector'
wp fpe garbage collect
```
