# Changelog

All notable changes to `d3creative/statamic-canary` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [1.3.2] - 2026-11-03

### Fixed

- **Mailers from transport packages, such as MailerSend, pass the mailer check.** Canary checked
  each mailer on a new mail manager, which doesn't have the transports packages register, so
  every site sending through one failed with `Unsupported mail transport`.

## [1.3.1] - 2026-11-02

### Changed

- **The real `cc` and `bcc` are checked and listed.** Canary removes them from test emails, but
  the real email still goes to them, so an invalid one fails the run and `-v` and `--dry-run`
  show them alongside the `to` address.
- **`--scan` picks out failed form emails** from other failed jobs, with the date each failed,
  as each is an enquiry that never arrived. Other failed jobs, such as cache warming, are only
  counted.

## [1.3.0] - 2026-11-02

### Added

- **`canaryChecks()`** for a listener that sends to several services. Each service gets its own
  read-only check, is listed by name and passes or fails on its own. A check that returns a
  string passes with it as a warning, such as "disabled in Globals".
- **`--scan`** lists every form listener, anything that could email someone other than the test
  address (a custom `send_email_job`, a `MessageSending` listener or a global `mail.to`), each
  form's emails and the failed jobs waiting. It tests nothing and always exits 0.
- **Each email's mailer is created before anything is sent**, so a missing transport package or
  a mistyped `MAIL_MAILER` fails the run, `--dry-run` included, instead of failing later in the
  queue worker. Creating a mailer sends nothing.
- **More of a listener's code is read:** a variable assigned from the handle, `Str::startsWith()`
  and `str_starts_with()` prefixes matched against the site's forms, `match` and `switch` arms,
  and an early `return` that leaves a form out. Every service the code names is found, not just
  the first.

### Changed

- **`--subscribe` is refused for a listener that sends to more than one service**, by its checks
  or its code. It runs the whole listener, so the test contact would reach every service and
  could only be deleted from one.
- **A form an `else` branch or `default` arm may reach** isn't reported as having nothing to test.
- **Unregistered listeners in `app/Listeners` are only listed if they're for forms.**

## [1.2.0] - 2026-11-02

### Added

- **Emails built in code.** A listener that sets a form's emails when it's submitted can tell
  Canary about them with `canaryEmails()`. They replace the form's own settings, as on a real
  submission, and go to the test address like any other form email. A listener that only builds
  emails isn't listed as an integration.
- **Each email is checked before it's sent.** Its template must render, and its real recipients,
  read before the test address replaces them, must be valid email addresses. A placeholder
  address such as `hello@example.com` gets a warning. `-v` lists the real recipients.
- **`--dry-run`** builds and checks every email and runs the API checks, but sends nothing. It
  reports where each email would have gone instead, and says the mailer and queue weren't tested.
- **`--now`** sends the emails during the run instead of through the queue, so a mailer error
  fails it.
- **A summary at the end of every run**, and a reminder to check `queue:failed` when the emails
  went through a real queue.
- **Checks and `canaryEmails()` can only read.** Canary blocks any request through Laravel's HTTP
  client that isn't a GET or HEAD while they run, such as a token refresh, and fails the check
  even if the listener catches the error.

### Changed

- **A run that tests nothing at all now exits non-zero.** It used to warn and exit 0, which looked
  like a pass. A form with nothing to test alongside others still only warns.
- **A form isn't reported as having nothing to test when a listener might handle it.** The list
  names a listener that builds emails without `canaryEmails()`, or one whose forms Canary can't
  tell.
- **Emails that only differ by recipient are sent once**, saying how many they cover.
- **Checkboxes and multi-selects get every option** in test emails, so routing rules all fire.
  Data sent to a real service with `--subscribe` still gets one.
- **Validators, such as a reCAPTCHA listener, aren't warned about**, and a listener whose
  `canaryForms()` returns `[]` is left alone.

## [1.1.1] - 2026-11-02

### Fixed

- **`--subscribe` no longer leaves a contact behind when the listener uses a job.** Jobs the
  listener dispatches now run straight away, so the contact exists before Canary checks for it
  and deletes it. If a job forces a real queue connection anyway, Canary fails and says to
  delete the test address by hand once a worker has run it.

## [1.1.0] - 2026-10-30

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
