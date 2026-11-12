# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Canary is a Statamic 6 addon with one Artisan command, `canary:forms`, that checks a live site's form emails still get delivered and its integrations still work. It sends a form's real emails to one test address through the real queue, without saving a submission or firing the form events. Describe it as a **deliverability check**, not an email preview tool.

The `canary:` prefix leaves room for more checks. A scheduled or alerting mode and a Control Panel Utilities page may come later; neither is in v1.

Everything stays in `canary:forms`: there's deliberately no separate integrations command, so one picker and one saved list cover both.

There's no JavaScript. `src/ServiceProvider.php` only merges and publishes `config/canary.php` (tag `canary-config`). `AddonServiceProvider` registers commands in `src/Console/Commands` automatically.

## How it works

A real submission (`Statamic\Http\Controllers\FormController`) does four things: `makeSubmission()`, fire `FormSubmitted`, save the submission (which fires `SubmissionCreated`), then `SendEmails::dispatch()`. Canary does the first and last only. Skipping the events is the point: newsletter and CRM integrations listen on them. The one exception is `--submit` (see below), which deliberately does all four, for opted-in forms only.

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
- **Listeners without `canaryForms()` are read, not run.** `src/Forms/ListenerSource.php` strips comments with the tokenizer, then matches the handle patterns in its `handles()` method. It only reads files that mention `FormSubmitted` or `SubmissionCreated`, so a listener comparing a collection handle isn't mistaken for a form listener. Fields are only listed when a listener handles one form. It was checked against lbf-com's six ActiveCampaign listeners and d3creative's MailerLite and Acorn listeners, by reading their files only.
- **Listeners whose forms still can't be found** are warned about at the start of every run (`Integration::unknown()`), since their forms would otherwise look like they have no integrations. Package listeners are detected with `ClassLoader::getRegisteredLoaders()`, whose keys are vendor directories, and each loader's `findFile()`. Don't use `ClassLoader`'s own file path: Herd preloads a copy from `dump.phar`. Don't use Reflection's file name either: it resolves the symlink a path repository installs, so Acorn on d3creative looked like app code. A package listener is named after its addon (`Addon::get()` with the Composer name from its vendor path), or the Composer name when it isn't one, and isn't marked `(no API check)`, since the site can't add one.
- **`--subscribe` runs one listener directly** (`Integration::run()`), never the event, so other listeners don't run. It passes a `TestSubmission`, whose `save()` does nothing, because real listeners save the submission (d3creative's `MailerLiteSubscriber` does), and that would store it and fire `SubmissionCreated`. It also runs with `queue.default` set to `sync`, so a listener that adds the contact from a job (lbf-com's do) has added it before Canary checks. A job that forces its own connection still fires `JobQueued`, which the sync queue never does, so Canary reports it instead of missing the contact and leaving it behind.
- **`canaryChecks()`** returns name => callable. Each runs inside `ReadOnlyRequests::run()` and is reported on its own; a returned string is a warning. It wins over `canaryCheck()`.
- **`--subscribe` is refused** when `Integration::subscribeRefusal()` finds several checks or several services named in the code. `run()` runs the whole listener, so a second service would keep the test contact. This is the main safety rule in 1.3; don't loosen it without a test.
- **`--scan`** returns before `--to` is checked. It must stay read-only: it reads `canaryForms()` but never calls `canaryEmails()`, a check, or a listener's `handle()`. `ScanTest` makes `canaryEmails()` throw to prove it.
- **`ListenerSource::handles()`** builds its patterns around a handle expression: the `handle()` call or a variable assigned from it. An early return after `===` removes a form; after `!==` it still means "handles only this", as Acorn does.
- **Refuse to subscribe an address that's already a contact.** `canaryDelete()` would remove a real subscription.
- **`canaryEmails()`** lets a listener that builds a form's emails in code (Outernet's `HandleFormSubmissions` does) declare them. Canary never runs the listener to find out, as that would create real leads. The returned emails replace the form's settings and go through the same checks and swap. `Integration::onlyBuildsEmails()` keeps such a listener out of the integration list. Without the method, `ListenerSource::buildsEmails()` spots the `->email(...)` setter, and the picker and run name the listener instead of saying there's nothing to test.
- **Checks and `canaryEmails()` run inside `ReadOnlyRequests::run()`**, a global HTTP client middleware that blocks anything but GET, HEAD and OPTIONS and records what it blocked, so a listener that catches the exception still fails. It's added once per HTTP factory because global middleware can't be removed. It doesn't cover SDKs with their own HTTP client.
- **Each email is rendered before dispatch** through a throwaway array mailer bound as `mailer`, because `Mailable::render()` resolves the site's mailer, and one that can't be built would look like a broken template. Real recipients are read with `Email::build()` on the original config, before the swap hides them.
- **`--dry-run` stops in `sendEmails()` after the checks and render**, before the form's emails are swapped or anything is dispatched. It still runs API checks, which are read-only and guarded. It refuses `--subscribe` and `--now`.
- **`checkMailers()` creates each email's mailer on a clone of the app's `MailManager`**, with `forgetMailers()` called on the clone, so a missing transport fails before dispatch. A fresh `MailManager` was wrong: packages such as MailerSend register their transport with `extend()` on the app's manager, so a new one said `Unsupported mail transport`. The clone keeps those creators but leaves the app's cached mailers alone, and under `Mail::fake()` it clones `MailFake::$manager`, the real one. Creating a transport doesn't connect or send.
- **A run that tests nothing exits non-zero**, as a pass that sent nothing is the false all-clear 1.2 was built to stop. A listener without an API check (read from source) isn't counted in `checksPassed`, and a form with no emails and only such listeners counts as untested (`$unchecked`). lbf-com's seven forms were exactly that and reported "7 integration checks passed".
- **Sample data never says "Canary"**, in any mode, as it ends up in client inboxes and services. Textareas get `testMessage()`, "Test submission from {canary.from_name}. Please ignore." (`CANARY_FROM_NAME`, default "D3 Creative"; empty drops "from …"), and other text fields "Test submission". `clientFacing` now only changes checkboxes (one option instead of all). A text field with an `email`/`email:*` rule gets the address too, and the form's honeypot field is never filled, as it would silently drop a real submission.

## --submit

`--submit` sends a real submission, for forms whose notifications come from a CRM automation, which a normal run can't reach. It runs after every normal check for the form has passed, and `sendEmails()` returns before dispatching in this mode, as the real submission sends them.

- **Only forms in `canary.submit`** (`CANARY_SUBMIT`, comma-separated). Any other handle fails the whole run before anything is tested. It's refused with `--dry-run`, `--subscribe` and `--now`, and `--real-recipients` needs it.
- **`src/Forms/RealSubmission.php`** builds a POST `Request` to `statamic.forms.submit`, clones that named route, `bind()`s it to the request and sets the `form` parameter to the form object (route model binding is middleware, which is skipped), then `FrontendFormRequest::createFrom()` + `validateResolved()` and `FormController::submit()`. `Accept: application/json` makes failures throw `ValidationException` and success return JSON (`submission_created` false means the honeypot or a listener returning false dropped it). `REMOTE_ADDR` is 127.0.0.1 so a listener's IP rate limit doesn't share the visitors' bucket, and the referer is the site's URL, which Statamic uses to pick the site. The request is bound as `request` and on the URL generator for the call, so listeners that inject `Request` get it, then restored. CSRF, the session and the route throttle are skipped.
- **Queued jobs are recorded with a `JobQueued` listener** that switches itself off, as in `Integration::run()`. `$event->queue` is null for a job on the connection's default queue, so it falls back to `queue.connections.{name}.queue`.
- **The address is new each run**: `submitAddress()` adds `+test-{form slug}-{YmdHis}` to `--to` (or `-test-…` when it already has a plus), so it's never an existing contact. It's still refused if `canaryContact()` finds it.
- **The form's emails are swapped on the in-memory form** for the call and restored in `finally`, as in a normal run. `--real-recipients` keeps the recipients (and cc/bcc) and only prefixes the subject. A listener that builds emails in code (`buildsEmails()` or `buildsHiddenEmails()`) is refused without `--real-recipients`, as those can't be redirected.
- **Each integration with `canaryContact()` is polled** every 5 seconds for up to 90 with `Illuminate\Support\Sleep`, then `DeleteTestContact` (stores only the listener class, form and ID) is queued with `delay(canary.delete_after minutes)`. On the `sync` queue a delay runs at once, so no delete is queued and the contact is left to delete by hand. Integrations without the two methods run for real and are warned about.

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
- `tests/Console/EmailsInCodeTest.php` uses `tests/Fixtures/HandleFormSubmissions.php`, shaped like Outernet's listener: staff routing by ticked interests, an auto-reply, and a recipient tests can empty or set to a placeholder.
- Emails are rendered before dispatch, so form emails in tests need a template. `tests/Fixtures/views` is added to `view.paths` in `TestCase`.
- With `Queue::fake()`, `SendEmails`' chain pushes only its first job; the rest are in that job's `chained`.
- **`tests/Console/SubmitTest.php`** freezes time (`Carbon::setTestNow`) so the plus address is predictable, and uses `Sleep::fake()`. `Queue::fake()` never fires `JobQueued`, so the test that checks the queue report uses a real `database` queue on testbench's SQLite, with a `jobs` table made in the test, and runs `AddToFakeCrm` from `Sleep::whenFakingSleep()` as the "worker". `SampledCrm` is a `FakeCrm` with `canarySample()`, kept separate so `FakeCrm`'s method list in `ScanTest` doesn't change.

## Manual checks

Against `../d3creative`, with a path repository that is **never committed** (that repo already has an uncommitted one for Darkroom):

```bash
MAIL_MAILER=log php artisan canary:forms contact upgrade_form discover_drip nope --to=you@example.com
```

Expect the test address and `TEST: ` subjects in `storage/logs/laravel.log`, a warning for `discover_drip`, an error for `nope`, and exit code 1. Then repeat with `QUEUE_CONNECTION=redis` and a `queue:work --stop-when-empty` worker: nothing is logged until the worker runs.

d3creative's `MailerLiteSubscriber` has the Canary methods. Running `canary:forms statamic_alerts statamic_maintenance_drip` makes read-only MailerLite API calls. Only run `--subscribe` there when Stephen asks: those groups start automations.

## Marketplace metadata

The `extra.statamic` block in `composer.json` is what the Statamic Marketplace reads. Keep it in sync with the README. A `v*` tag creates a GitHub release from that version's `CHANGELOG.md` section (`.github/workflows/release.yml`).
