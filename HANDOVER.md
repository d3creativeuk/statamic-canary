# Canary handover plan

Written 2 October 2026 at the end of a session in `../d3creative`. This file is the brief for building the addon in this repo. Delete it once v1.0.0 is released and the README and CLAUDE.md cover everything here.

## What Canary is

A Statamic 6 addon with one Artisan command that checks a live site's form emails still get delivered. It sends a form's **real** emails (its own template, subject, from address and mailer) to **one test address**, through the **real queue**, without saving a submission or running any form listeners.

It exists because the usual check, submitting the live form, makes a mess. A real submission saves a submission, signs the address up to newsletters, creates leads in a customer relationship management (CRM) system and emails client staff, who then ask what happened. Stephen checks forms after every update on the sites he maintains, so this runs often.

Describe it as a **deliverability check**, not an email preview tool. See "Positioning" below.

## Decisions already made

| Decision | Detail |
|---|---|
| Name | **Canary**. Stephen said "Canary feels like it might be ok", so confirm before tagging a release. Runners-up: Soundcheck, Rehearsal. |
| Package | `d3creative/statamic-canary`, namespace `D3Creative\Canary` |
| Support | **Statamic 6 only.** `"statamic/cms": "^6.0"`, `"php": "^8.3"`, matching statamic-tracer. Don't add Statamic 4 or 5. |
| Separate addon | Not part of Sentinel. Sentinel is about security; this is a maintenance tool. |
| Command prefix | `canary:` so later checks can sit under the same name. First command: `canary:forms`. |
| Scope | Stephen only wants to test **specific forms**. Many forms on a site are test or newsletter-only forms. No `--all` option. |

Checked on Packagist on 2 October 2026: no Statamic package is called Canary. `baspa/filament-canary` (a page smoke check for Filament) and `topshelfcraft/canary` (a Craft error handler) exist.

## Source material

- **The working command** is on the `blog/testing-statamic-form-emails` branch of `../d3creative` (commit `b4579066`), not merged to `main`:
  ```bash
  git -C ../d3creative show blog/testing-statamic-form-emails:app/Console/Commands/TestFormEmail.php
  ```
  It's ~120 lines and passes Pint. Port it rather than rewriting it.
- **Two draft blog posts** on the same branch explain the approach. Both are unpublished.
  - `content/collections/blog/testing-statamic-form-emails-live-site.md`: the Tinker recipe. Entry ID `e6c32ac3-e62d-4232-8c46-b19bbf520e84`.
  - `content/collections/blog/statamic-artisan-command-test-form-emails.md`: the command, with its full source. Entry ID `cc74a390-9e4b-4514-9ac0-c148ff925866`.
- **Template repo:** `../statamic-tracer`. Copy its `composer.json` shape, `phpunit.xml`, `.gitignore`, `tests/TestCase.php`, `.github/workflows/tests.yml` and `release.yml`, and its `CHANGELOG.md` format (Keep a Changelog).
- **Command pattern:** `../statamic-sentinel/src/Console/Commands/` has six commands.

## How it works (verified against Statamic 6.34.1)

Read `vendor/statamic/cms/src/Http/Controllers/FormController.php`. A real submission does this, in order:

1. `$form->makeSubmission()` and fills it with the posted data
2. fires `FormSubmitted`, where listeners can cancel it
3. if the form stores submissions, `$submission->save()`, which fires `SubmissionCreated`. If it doesn't store them, the controller fires `SubmissionCreated` itself
4. `SendEmails::dispatch($submission, $site)`

Canary does steps 1 and 4 only. Skipping 2 and 3 is the whole point, because newsletter and CRM integrations listen on those events.

Facts the code relies on:

- **`Form::email($config)`** is a fluent setter. It changes the in-memory form only. Never call `$form->save()`, or the client's real recipients get overwritten.
- **`$form->email()`** usually returns a list of email configs, but can be a **single associative array** with a `to` key. Normalise it: `isset($emails['to']) ? [$emails] : $emails`. `SendEmails::emailConfigs()` does the same.
- **`Statamic\Forms\SendEmails`** is **not** `ShouldQueue`. It runs at once and calls `Bus::chain($jobs)->dispatch()`, where each job is `config('statamic.forms.send_email_job')` (default `Statamic\Forms\SendEmail`, which *is* `ShouldQueue`). If the form has a `files` field, it also chains `DeleteTemporaryAttachments`. So if the emails arrive, the queue worker ran them.
- **`SendEmails` is an internal class**, not documented public API. This is the main maintenance risk. CI should test the lowest and latest Statamic 6 releases (see Continuous integration (CI) below).
- **`Statamic\Forms\Email`** reads these config keys: `to`, `from`, `reply_to`, `cc`, `bcc`, `subject`, `html`, `text`, `markdown`, `attachments`, `mailer`. Values are parsed as Antlers templates against the submission data. Canary keeps every key except `to` (replaced), `subject` (prefixed with `TEST: `), and `cc`/`bcc` (removed). Remove them rather than setting them to null: null values make Antlers log `str_replace(): Passing null` deprecation warnings.
- **The unsaved submission survives serialisation.** It was tested through a real Redis queue worker, and both emails were delivered. The recipients travel in each `SendEmail` job's `$config`, not on the form, so the worker never sees the client's real recipients.
- **Select options come in three formats.** `HasSelectOptions::getOptions()` accepts a plain list (`['a', 'b']`), `key: label` pairs (`['a' => 'A']`), or a list of `['key' => 'a', 'value' => 'A']` items. `firstOption()` handles all three, and this has been tested.
- **No validation runs.** Sample values only need to render in a template, not pass the blueprint's rules. That's intended: Canary tests the email path, not the form.

## v1 scope

### The command

The current signature is `app:test-form-email {to} {forms*}`. Proposed for the addon (**confirm with Stephen** before building):

```
canary:forms {forms?*} {--to=} {--site=}
```

- `forms`: form handles. If none are given, use `config('canary.forms')`. If that's empty too, fail with a message that says how to set it.
- `--to`: the test address. Fall back to `config('canary.to')`, which reads `env('CANARY_TO')`, then fail. The blog posts use a `TEST_EMAIL` shell variable (`--to="$TEST_EMAIL"` works with that).
- `--site`: site handle for multisite. Defaults to `Site::default()`, which is what the command uses now. Fail on an unknown handle.

With the config set per site, the check after each update becomes `php artisan canary:forms`.

### Behaviour to keep from the current command

- Validate the address with `FILTER_VALIDATE_EMAIL`.
- Warn when `config('queue.default') === 'sync'`: there's no worker to test, so only templates and sender are checked.
- For each form handle:
  - Unknown handle: show an error, carry on, mark the run as failed.
  - No emails configured: show a warning, carry on, don't fail. This is normal for newsletter-only forms; their work happens in the listeners Canary deliberately skips.
  - An exception from `SendEmails`: show an error with the handle, carry on, mark the run as failed.
  - Success: report `N email(s) for {handle} dispatched to {to}.`
- Exit non-zero if any form failed, so deploy scripts can stop.
- Sample values from the blueprint (`sampleValue()`):
  - email `input_type`: the test address (so automatic replies to the visitor come back to you)
  - `url`: `https://example.com`
  - `tel`: `000`
  - `assets` and `files`: skipped (null, filtered out)
  - `integer`: 1
  - `toggle`: true
  - `date`: today's date
  - `checkboxes`: `[first option]`
  - any field with options: first option
  - `textarea`: `Sent by Canary. Please ignore.`
  - everything else: `Test`. Keep it short, because subjects often include a field, e.g. `{{ full_name }} submitted the contact form.` arrives as "TEST: Test submitted the contact form."
- Keep the `RunsInPlease` trait, so `./please canary:forms` works.

### Config file

`config/canary.php`, publishable:

```php
return [
    // Where test emails go. Usually set per environment.
    'to' => env('CANARY_TO'),

    // Form handles checked when canary:forms runs without arguments.
    'forms' => [],
];
```

### Out of scope for v1

- Running on a schedule and alerting when a form stops sending. The name leaves room for this, but it's v2 at the earliest.
- A Control Panel utility page.
- Checking that newsletter or CRM listeners work. Canary deliberately skips them.
- Confirming the email reached an inbox. Canary proves the job ran; the person running it checks their inbox.

## Repo layout

No JavaScript or Vite, unlike Tracer. There's no `package.json`.

```
.github/workflows/tests.yml     # from Tracer, plus a lowest-deps job
.github/workflows/release.yml   # copy from Tracer as is
config/canary.php
src/ServiceProvider.php
src/Console/Commands/FormsCommand.php
tests/TestCase.php
tests/Console/FormsCommandTest.php
.gitignore                      # from Tracer (ignores composer.lock)
CHANGELOG.md
CLAUDE.md
LICENSE                         # MIT, as Tracer
README.md
composer.json
phpunit.xml
```

### composer.json

Based on Tracer's:

```json
{
    "name": "d3creative/statamic-canary",
    "description": "Canary by D3 Creative - checks a Statamic site's form emails still send, without saving a submission or emailing the client.",
    "type": "statamic-addon",
    "license": "MIT",
    "authors": [{ "name": "D3 Creative", "homepage": "https://d3creative.uk" }],
    "require": {
        "php": "^8.3",
        "statamic/cms": "^6.0"
    },
    "require-dev": {
        "orchestra/testbench": "^10.8 || ^11.0",
        "phpunit/phpunit": "^12.5"
    },
    "autoload": { "psr-4": { "D3Creative\\Canary\\": "src/" } },
    "autoload-dev": { "psr-4": { "D3Creative\\Canary\\Tests\\": "tests/" } },
    "extra": {
        "statamic": {
            "name": "Canary",
            "description": "Send a form's real emails to yourself through the queue, without saving a submission or running its integrations.",
            "developer": "D3 Creative",
            "developer_url": "https://d3creative.uk",
            "tags": ["forms", "email", "maintenance"]
        },
        "laravel": { "providers": ["D3Creative\\Canary\\ServiceProvider"] }
    },
    "prefer-stable": true
}
```

### ServiceProvider

Statamic's `AddonServiceProvider::bootCommands()` loads any `Command` in `src/Commands` or `src/Console/Commands` automatically, when running in the console. The provider only needs to merge and publish the config:

```php
class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/canary.php', 'canary');
    }

    public function bootAddon(): void
    {
        $this->publishes([__DIR__.'/../config/canary.php' => config_path('canary.php')], 'canary-config');
    }
}
```

Verified on 6.34.1: `bootAddon()` is defined on `AddonServiceProvider`, and `register()` is inherited from Laravel's base `ServiceProvider`, so calling `parent::register()` is safe.

## Tests

`tests/TestCase.php` extends `Statamic\Testing\AddonTestCase`, as in Tracer. In each test, create a form and its blueprint (namespace `forms`) with an email config pointing at a "real" recipient such as `client@example.com`.

Cases to cover:

1. Sends each configured email to the test address only. The real recipient, `cc` and `bcc` don't receive anything.
2. Subject gets the `TEST: ` prefix; the template, `from` and `mailer` keys are kept.
3. `FormSubmitted` and `SubmissionCreated` are **not** dispatched (`Event::fake([...])` for those two only).
4. With `store: true`, no submission is saved.
5. The form's saved email config is unchanged after the run (re-find the form; the real recipient is still there).
6. A single associative email config (with a `to` key) is handled.
7. A form with no emails: warning, exit 0.
8. Unknown handle: error, exit non-zero, and the other forms still run.
9. Invalid address: error, exit non-zero.
10. `sync` queue connection: shows the warning.
11. `sampleValue()` for each field type, and `firstOption()` for all three option formats.
12. Config fallbacks: no `--to` uses `canary.to`; no handles uses `canary.forms`; neither set gives a helpful failure.
13. `--site` with an unknown handle fails.

**Test fakes to be careful with:**

- **Don't use `Bus::fake()` globally.** Laravel's `BusFake` intercepts `dispatchSync`, so `SendEmails` would never run and nothing would be tested. Either:
  - set the queue to `sync` and use `Mail::fake()`, then assert on `Statamic\Forms\Email`; or
  - use `Queue::fake()` and assert the `SendEmail` jobs (and their chain) were pushed with `$job->config['to']` set to the test address.
- `Mailable::hasTo()` doesn't build the mailable. `Statamic\Forms\Email` sets its recipients in `build()`, so use `assertHasTo()`, which renders first, or check `$job->config` instead. Check this when writing the tests.

## Continuous integration (CI)

- **`tests.yml`:** copy Tracer's matrix (PHP 8.3, 8.4, 8.5; `composer validate --strict`; `composer update`; phpunit). Add one job with `composer update --prefer-lowest` on PHP 8.3, so the lowest Statamic 6 release is tested too. A change to `SendEmails` in a 6.x minor release is the most likely break.
- **`release.yml`:** copy as is. A `v*` tag creates a GitHub release from that version's `CHANGELOG.md` section, and the Statamic Marketplace reads it.

## Manual checks before tagging v1.0.0

Run these against `../d3creative`. Its forms:
- `contact`, `upgrade_form`, `migration_form`, `statamic_maintenance_contact` and `ask_a_question`: one email each, fields `full_name`, `business_email`, then `message_text` or `website_address`
- `discover_drip` and `statamic_maintenance_drip`: no emails

1. **Point the host at this checkout.** Add a path repository to `../d3creative/composer.json` and run `composer require d3creative/statamic-canary:@dev`. **Never commit a path repository** (a standing rule in that repo). `../d3creative` already has an uncommitted path repository for Darkroom, so add to its `repositories` array without committing either.
2. **Log only:**
   ```bash
   MAIL_MAILER=log php artisan canary:forms contact upgrade_form discover_drip nope --to=you@example.com
   ```
   Then check `storage/logs/laravel.log`. Expect `To: you@example.com`, subjects like `TEST: Test submitted the contact form.`, `From: D3 Creative <s.meehan@d3creative.uk>`, a warning for `discover_drip`, an error for `nope`, and exit code 1.
3. **Real queue worker:** Redis runs locally (Herd).
   ```bash
   export MAIL_MAILER=log QUEUE_CONNECTION=redis REDIS_QUEUE=canarytest
   php artisan canary:forms contact upgrade_form --to=you@example.com
   php artisan queue:work redis --queue=canarytest --stop-when-empty --tries=1
   ```
   Nothing should be logged before the worker runs; both emails should be logged after. Locally, `queue:failed` errors because there's no MySQL, which is unrelated.
4. **Mail catcher:** run without `MAIL_MAILER=log`. Local mail goes to Herd's catcher on port 2525.
5. **Live server:** run on one maintained client site with a real mail provider and a real worker. This has **not been done yet** for any version of the command.
6. **Marketplace review:** run the `marketplace-review` skill on this repo before submission.

## Positioning: Canary vs statamic/mailables-viewer

Stephen asked whether `statamic/mailables-viewer` (first-party, Statamic 6) already does this. It doesn't. That was confirmed by reading its README and source; it wasn't installed.

| | Mailables Viewer | Canary |
|---|---|---|
| Purpose | Preview `app/Mail` mailables in the Control Panel | Check form emails still send after an update |
| Form emails | Not discovered: `Statamic\Forms\Email` is in vendor and has to be registered by hand | By form handle |
| Form settings | Passes `[]` for array arguments, so no template, subject or from address | Uses the form's own settings |
| Submission data | `FormSubmission::query()->first()`: any form's first stored submission, or none if storing is off | Sample data from the form's blueprint |
| Queue | `sendNow()`, so it skips the queue | Goes through the queue |
| Runs from | Control Panel (needs a login) | Command line, so it fits a checklist or deploy script |

Put a short version of this in the README. They complement each other.

## Follow-ups in ../d3creative once v1.0.0 is on Packagist

1. `composer require d3creative/statamic-canary` (from Packagist, not a path), and delete `app/Console/Commands/TestFormEmail.php`.
2. **Post 2** (`statamic-artisan-command-test-form-emails.md`):
   - mention the addon as the easy option, keeping the source for anyone who wants to copy it
   - add one sentence on how it differs from Mailables Viewer
   - change `you@example.com` to `"$TEST_EMAIL"`, matching post 1 (Stephen hasn't answered whether he wants this yet)
3. **Post 1:** its "Turn it into a command" section shows `app:test-form-email`. Decide whether it stays as the copy-it-yourself version or points at `canary:forms`.
4. **Both posts** still need a lead image.
5. **`../d3creative/CLAUDE.md`** (gitignored, local only) lists `app:test-form-email` under Key Commands. Update it to `canary:forms`.
6. **Merge** `blog/testing-statamic-form-emails` when the posts are ready.
7. **Memory:** update `project_form_email_addon.md` in the d3creative project memory with the final name and release.

## Open questions for Stephen

1. Is **Canary** final?
2. Signature: are `canary:forms {forms?*} {--to=} {--site=}` and a config fallback OK, or should it stay positional like `app:test-form-email`?
3. Ship the config file in v1, or keep v1 to arguments only?
4. Free on the marketplace, like Tracer?
5. Is a scheduled or alerting mode wanted later? It affects whether `canary:` gets more commands.
