# Changelog

All notable changes to `d3creative/statamic-canary` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

### Added

- **Integration checks.** A form listener, such as one that adds people to MailerLite or
  ActiveCampaign, can tell Canary which forms and fields it handles through plain methods
  (`canaryForms()`, `canaryName()`, `canaryCheck()`), with no dependency on Canary.
  `canary:forms` then checks each one is registered, the form still has the fields it reads,
  and a read-only API call succeeds. Listeners in `app/Listeners` that have the methods but
  aren't registered are reported, and every run warns about form listeners that don't have
  the methods yet, so their forms aren't mistaken for having no integrations.
- **`--subscribe`** runs each integration's listener alone with a test submission, checks the
  contact arrived, then deletes it. It needs `canaryContact()` and `canaryDelete()`, and
  refuses to run if the test address is already a contact.

### Changed

- **The form picker says what each form tests**, such as `contact - emails` or
  `newsletter - emails, MailerLite`. Forms with nothing to test show
  `no emails or integrations found` and are still listed last.
- **A form with integrations but no emails is tested**, instead of warning that there's
  nothing to test.

## [1.0.0] - 2026-10-23

Initial release.

### Added

- **`canary:forms` checks a form's emails still send, without a real submission.** It sends
  each of the form's real emails (template, subject, from address and mailer) to one test
  address through the site's queue, with `TEST: ` before the subject and `cc` and `bcc`
  removed. No submission is saved, `FormSubmitted` and `SubmissionCreated` aren't fired, so
  newsletter and CRM integrations never see it, and the form itself is never changed. It exits
  non-zero if a form is missing or its emails fail to dispatch, so a deploy script can stop.
- **A list of the site's forms to pick from.** Run with no handles and Canary lists every form,
  with the ones that send no emails at the bottom. After picking, it offers to save the choice,
  and later runs, deploy scripts included, test the saved forms without asking. `--pick` shows
  the list again.
- **Config for the test address and default forms.** `CANARY_TO` sets the address, and
  `canary.forms` in `config/canary.php` lists forms to test when none are passed.
- **`--site`** sends as a given site on a multisite.
