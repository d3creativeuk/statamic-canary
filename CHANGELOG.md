# Changelog

All notable changes to `d3creative/statamic-canary` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

### Added

- `canary:forms`, which sends a form's real emails to one test address through the queue,
  without saving a submission or firing `FormSubmitted` and `SubmissionCreated`. Handles come
  from the arguments or `canary.forms`. If neither gives any, an interactive run lists every
  form to pick from.
- `config/canary.php`, with the test address (`CANARY_TO`) and the forms checked by default.
