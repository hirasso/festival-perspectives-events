# Changelog: `hirasso/festival-perspectives-events`

## 1.1.4

### Patch Changes

- 15a7b65: Minor cleanup

## 1.1.3

### Patch Changes

- ad569f5: Preserve query args in the 'term_link' filter (for example to preserve the 'year' query param)

## 1.1.2

### Patch Changes

- 413bd57: Rename `getYearsWithEvents()` to `getYears()`
- 413bd57: Use a new function `runUnfiltered()` for querying the database
- 413bd57: Add a few tests

## 1.1.1

### Patch Changes

- 7b81cad: Renamed the shortcut function to the public API from `fp_events()` to `fpe()`

## 1.1.0

### Minor Changes

- bc86174: Add a scheduled job `fp_events_run_archiver` that deletes expired recurrences on a daily basis

### Patch Changes

- ea8a7fd: New wp-cli command: `wp events recurrences create <post-id>...`
- da5c258: Add new API method `fp_events()->isEventInThePast()`

## 1.0.1

### Patch Changes

- d964c3c: Optimize queries that are grouped by a meta field

## 1.0.0

### Major Changes

- fc0d424: First Major Version. Renamed from `hirasso/acf-events` to `hirasso/festival-perspectives-events` to communicate clearly that this library specifically tied to https://festival-perspectives.de/programm` and any of it's pretty specific requirements.

## 0.0.1

### Patch Changes

- 65df026: Add ci pipeline for tests and versioning
- 8975ba9: Link translations of generated recurrences
- 65df026: Add basic php and e2e tests
