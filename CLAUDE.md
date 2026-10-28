# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Canary is a Statamic 6 addon with one Artisan command, `canary:forms`, that checks a live site's form emails still get delivered and its integrations still work. It sends a form's real emails to one test address through the real queue, without saving a submission or firing the form events. Describe it as a **deliverability check**, not an email preview tool.

The `canary:` prefix leaves room for more checks. A scheduled or alerting mode and a Control Panel Utilities page may come later; neither is in v1.

Everything stays in `canary:forms`: there's deliberately no separate integrations command, so one picker and one saved list cover both.

There's no JavaScript. `src/ServiceProvider.php` only merges and publishes `config/canary.php` (tag `canary-config`). `AddonServiceProvider` registers commands in `src/Console/Commands` automatically.

## How it works

A real submission (`Statamic\Http\Controllers\FormController`) does four things: `makeSubmission()`, fire `FormSubmitted`, save the submission (which fires `SubmissionCreated`), then `SendEmails::dispatch()`. Canary does the first and last only. Skipping the events is the point: newsletter and CRM integrations listen on them.

- **`Form::email($config)` changes the in-memory form only.** Never call `$form->save()` in the command, or the client's real recipients are overwritten.
- **`$form->email()` can be a list or a single associative array** with a `to` key. `emailConfigs()` normalises it, as `SendEmails::emailConfigs()` does.
- **`Statamic\Forms\SendEmails` is internal**, not public API, and the main maintenance risk. It isn't `ShouldQueue`: it runs at once and `Bus::chain()`s one `config('statamic.forms.send_email_job')` per email (default `SendEmail`, which is queued). CI runs a `--prefer-lowest` job so the oldest Statamic 6 release is tested too.
- **`$form->store(false)` on the in-memory form is required.** For forms with a `files` field, `SendEmails` chains `DeleteTemporaryAttachments`, which calls `saveQuietly()` on the submission when the form stores submissions. The whole form object is serialised into the jobs, so the in-memory changes reach a queue worker too.
- **Remove `cc` and `bcc`, don't null them.** Null values make Antlers log `str_replace(): Passing null` deprecations.
- The unsaved submission serialises through a real queue; the recipients travel in each job's `$config`, not on the form.
- `firstOption()` handles all three select option formats from `HasSelectOptions::getOptions()`: a list, `key: label` pairs, and `['key' => …, 'value' => …]` items.
- No validation runs. Sample values only need to render.

Form handles come from the arguments, then `config('canary.forms')`, then the `forms` addon setting, then a Laravel Prompts `multiselect()` over `Form::all()` when the run is interactive. `--pick` skips the config and saved forms. After picking, the command offers to save the choice to the addon settings (`resources/addons/statamic-canary.yaml`); it doesn't offer when `canary.forms` is set, since the config would win anyway. The addon has no settings blueprint, so there's no Control Panel settings page. There's deliberately no `--all`: many sites have test or newsletter-only forms.

## Integrations

`src/Forms/Integration.php` finds form listeners that opt in by having a `canaryForms()` method. The other methods (`canaryName`, `canaryCheck`, `canaryContact`, `canaryDelete`) are optional; the class docblock lists their signatures. Canary checks for them with `method_exists()` and never ships an interface, so a site's listeners don't depend on Canary.

- **Registered listeners** come from `Event::getRawListeners()` for `FormSubmitted` and `SubmissionCreated`, in all three formats: `Class`, `Class@method` and `[Class, method]`. Closures are skipped.
- **Unregistered listeners** are found by scanning `app_path('Listeners')` with `app()->getNamespace()`, so a listener dropped from `EventServiceProvider` is reported. If the namespace can't be detected, the scan returns nothing.
- **Listeners without `canaryForms()`** are warned about at the start of every run (`Integration::unconfigured()`), since their forms would otherwise look like they have no integrations. Package listeners are detected with `ClassLoader::getRegisteredLoaders()`, whose keys are vendor directories. Don't use `ClassLoader`'s own file path: Herd preloads a copy from `dump.phar`.
- **`--subscribe` runs one listener directly** (`Integration::run()`), never the event, so other listeners don't run. It passes a `TestSubmission`, whose `save()` does nothing, because real listeners save the submission (d3creative's `MailerLiteSubscriber` does), and that would store it and fire `SubmissionCreated`.
- **Refuse to subscribe an address that's already a contact.** `canaryDelete()` would remove a real subscription.
- **Client-facing sample data never says "Canary".** `sampleData(..., clientFacing: true)` uses "TEST form submission" for text and textareas, because it ends up in the client's own MailerLite or ActiveCampaign. Emails to the test address only reach a developer, so they keep "Sent by Canary. Please ignore."

## Tests

```bash
composer install
vendor/bin/phpunit
```

`tests/TestCase.php` points forms, blueprints and submissions at `tests/__fixtures__/tmp` (gitignored) and deletes it after each test. `makeForm()` creates a form and its blueprint.

- **Don't use `Bus::fake()`.** `BusFake` intercepts `dispatchSync`, so `SendEmails` never runs. Tests use the `sync` queue with `Mail::fake()` and assert on `Statamic\Forms\Email::getConfig()`, or `Queue::fake()` and assert on `SendEmail::$config`.
- `Email` sets its recipients and subject in `build()`, so call `$mail->build()` before `hasTo()` or reading `subject`.
- The picker test uses `expectsChoice()`; Laravel swaps Prompts for Symfony questions under unit tests.
- `tests/Console/IntegrationsTest.php` uses `tests/Fixtures/FakeCrm.php`, a listener with an in-memory CRM. `useListenersIn('App')` points `app_path()` and the app namespace at `tests/Fixtures/App`, whose `Listeners` folder holds an unregistered listener.

## Manual checks

Against `../d3creative`, with a path repository that is **never committed** (that repo already has an uncommitted one for Darkroom):

```bash
MAIL_MAILER=log php artisan canary:forms contact upgrade_form discover_drip nope --to=you@example.com
```

Expect the test address and `TEST: ` subjects in `storage/logs/laravel.log`, a warning for `discover_drip`, an error for `nope`, and exit code 1. Then repeat with `QUEUE_CONNECTION=redis` and a `queue:work --stop-when-empty` worker: nothing is logged until the worker runs.

d3creative's `MailerLiteSubscriber` has the Canary methods. Running `canary:forms statamic_alerts statamic_maintenance_drip` makes read-only MailerLite API calls. Only run `--subscribe` there when Stephen asks: those groups start automations.

## Marketplace metadata

The `extra.statamic` block in `composer.json` is what the Statamic Marketplace reads. Keep it in sync with the README. A `v*` tag creates a GitHub release from that version's `CHANGELOG.md` section (`.github/workflows/release.yml`).
