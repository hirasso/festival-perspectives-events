# Changelog: `hirasso/festival-perspectives-events`

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
