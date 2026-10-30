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
  aren't registered are reported.
- **Listeners without the methods are found from their code.** Canary recognises the usual
  ways a listener picks its forms (comparing the handle, `in_array()`, a handle-keyed array, or
  a handle from config) and finds the fields it reads and the service it names. Those forms are
  checked for registration and fields, and listed as `(no API check)`. A listener from a
  package is named after its addon or package instead. Every run warns about form listeners
  whose forms still can't be found.
- **`--subscribe`** runs each integration's listener alone with a test submission, checks the
  contact arrived, then deletes it. It needs `canaryContact()` and `canaryDelete()`, and
  refuses to run if the test address is already a contact.
- **Every run names the queue connection and queue** the emails go on, such as
  `the default queue of the redis connection`, so you know which worker has to run them.
- **Every run names the mailer**, and warns when it's `log` or `array`. For `smtp` it adds the
  host and port, so a local mail catcher is obvious. A form email with its own `mailer` names it
  when it's dispatched.

### Changed

- **The form picker says what each form tests**, such as `contact - notification email` or
  `newsletter - auto-reply email, MailerLite`. Forms with nothing to test show
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
