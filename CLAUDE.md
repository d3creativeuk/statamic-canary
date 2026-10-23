# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Canary is a Statamic 6 addon with one Artisan command, `canary:forms`, that checks a live site's form emails still get delivered. It sends a form's real emails to one test address through the real queue, without saving a submission or running form listeners. Describe it as a **deliverability check**, not an email preview tool.

The `canary:` prefix leaves room for more checks. A scheduled or alerting mode and a Control Panel Utilities page may come later; neither is in v1.

There's no JavaScript. `src/ServiceProvider.php` only merges and publishes `config/canary.php` (tag `canary-config`). `AddonServiceProvider` registers commands in `src/Console/Commands` automatically.

## How it works

A real submission (`Statamic\Http\Controllers\FormController`) does four things: `makeSubmission()`, fire `FormSubmitted`, save the submission (which fires `SubmissionCreated`), then `SendEmails::dispatch()`. Canary does the first and last only. Skipping the events is the point: newsletter and CRM integrations listen on them.

- **`Form::email($config)` changes the in-memory form only.** Never call `$form->save()` in the command, or the client's real recipients are overwritten.
- **`$form->email()` can be a list or a single associative array** with a `to` key. `emailConfigs()` normalises it, as `SendEmails::emailConfigs()` does.
- **`Statamic\Forms\SendEmails` is internal**, not public API, and the main maintenance risk. It isn't `ShouldQueue`: it runs at once and `Bus::chain()`s one `config('statamic.forms.send_email_job')` per email (default `SendEmail`, which is queued). CI runs a `--prefer-lowest` job so the oldest Statamic 6 release is tested too.
- **Remove `cc` and `bcc`, don't null them.** Null values make Antlers log `str_replace(): Passing null` deprecations.
- The unsaved submission serialises through a real queue; the recipients travel in each job's `$config`, not on the form.
- `firstOption()` handles all three select option formats from `HasSelectOptions::getOptions()`: a list, `key: label` pairs, and `['key' => …, 'value' => …]` items.
- No validation runs. Sample values only need to render.

Form handles come from the arguments, then `config('canary.forms')`, then a Laravel Prompts `multiselect()` over `Form::all()` when the run is interactive. There's deliberately no `--all`: many sites have test or newsletter-only forms.

## Tests

```bash
composer install
vendor/bin/phpunit
```

`tests/TestCase.php` points forms, blueprints and submissions at `tests/__fixtures__/tmp` (gitignored) and deletes it after each test. `makeForm()` creates a form and its blueprint.

- **Don't use `Bus::fake()`.** `BusFake` intercepts `dispatchSync`, so `SendEmails` never runs. Tests use the `sync` queue with `Mail::fake()` and assert on `Statamic\Forms\Email::getConfig()`, or `Queue::fake()` and assert on `SendEmail::$config`.
- `Email` sets its recipients and subject in `build()`, so call `$mail->build()` before `hasTo()` or reading `subject`.
- The picker test uses `expectsChoice()`; Laravel swaps Prompts for Symfony questions under unit tests.

## Manual checks

Against `../d3creative`, with a path repository that is **never committed** (that repo already has an uncommitted one for Darkroom):

```bash
MAIL_MAILER=log php artisan canary:forms contact upgrade_form discover_drip nope --to=you@example.com
```

Expect the test address and `TEST: ` subjects in `storage/logs/laravel.log`, a warning for `discover_drip`, an error for `nope`, and exit code 1. Then repeat with `QUEUE_CONNECTION=redis` and a `queue:work --stop-when-empty` worker: nothing is logged until the worker runs.

## Marketplace metadata

The `extra.statamic` block in `composer.json` is what the Statamic Marketplace reads. Keep it in sync with the README. A `v*` tag creates a GitHub release from that version's `CHANGELOG.md` section (`.github/workflows/release.yml`).
