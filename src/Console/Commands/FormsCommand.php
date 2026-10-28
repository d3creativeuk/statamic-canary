<?php

namespace D3Creative\Canary\Console\Commands;

use D3Creative\Canary\Forms\Integration;
use D3Creative\Canary\Forms\TestSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Addons\Settings;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Addon;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Forms\SendEmails;
use Statamic\Sites\Site as SiteObject;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class FormsCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'canary:forms
                            {forms?* : Handles of the forms to test. Defaults to canary.forms, then the saved forms, then a list to pick from}
                            {--to= : The address that receives every test email. Defaults to canary.to (CANARY_TO)}
                            {--site= : Handle of the site to send as. Defaults to the default site}
                            {--pick : Choose forms from the list, even if some are configured or saved}
                            {--subscribe : Also add the test address through each integration, then delete it}';

    private const INTEGRATION_DOCS = 'https://github.com/d3creativeuk/statamic-canary#checking-integrations';

    protected $description = "Send a form's emails to one address and check its integrations, without saving a submission";

    private ?Collection $integrations = null;

    public function handle(): int
    {
        $to = $this->option('to') ?: config('canary.to');

        if (! $to) {
            $this->components->error('No test address. Pass --to or set CANARY_TO in .env.');

            return self::FAILURE;
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("{$to} is not an email address.");

            return self::FAILURE;
        }

        $site = $this->option('site') ? Site::get($this->option('site')) : Site::default();

        if (! $site) {
            $this->components->error("No site with the handle {$this->option('site')}.");

            return self::FAILURE;
        }

        $this->warnAboutUnconfiguredListeners();

        if (! $handles = $this->handles()) {
            return self::FAILURE;
        }

        if (config('queue.default') === 'sync') {
            $this->components->warn('The queue connection is sync, so this tests the templates and sender but not a queue worker.');
        }

        $failed = false;

        foreach ($handles as $handle) {
            $failed = ! $this->testForm($handle, $to, $site) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function handles(): array
    {
        if ($handles = $this->argument('forms')) {
            return $handles;
        }

        if (! $this->option('pick')) {
            if ($handles = Arr::wrap(config('canary.forms'))) {
                return $handles;
            }

            if ($handles = $this->settings()->get('forms', [])) {
                $this->components->info('Testing the saved forms. Run with --pick to choose again.');

                return $handles;
            }
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No forms to test. Pass form handles as arguments, list them in canary.forms, or run interactively to pick and save them.');

            return [];
        }

        $forms = Form::all();

        if ($forms->isEmpty()) {
            $this->components->error('No forms found.');

            return [];
        }

        $tests = $forms->mapWithKeys(fn (FormContract $form) => [$form->handle() => $this->tests($form)]);

        // Forms with nothing to test go last.
        [$testable, $untestable] = $tests->partition(fn ($tests) => $tests);

        $handles = multiselect(
            label: 'Which forms should Canary test? '.$forms->count().' '.Str::plural('form', $forms->count()).' found.',
            options: $testable->map(fn ($tests, $handle) => "{$handle} - ".implode(', ', $tests))
                ->merge($untestable->map(fn ($tests, $handle) => "{$handle} - no emails or integrations found"))
                ->all(),
            scroll: 10,
            required: true,
            hint: 'Press space to select.',
        );

        // canary.forms wins over saved forms, so saving would have no effect.
        if (! config('canary.forms') && confirm('Save these as the forms to test next time?', default: false)) {
            $this->settings()->set('forms', $handles)->save();
            $this->components->info('Saved. Run with --pick to choose again.');
        }

        return $handles;
    }

    private function integrations(string $form): Collection
    {
        $this->integrations ??= Integration::all();

        return $this->integrations->filter(fn (Integration $integration) => $integration->handles($form))->values();
    }

    /**
     * Without the Canary methods, a listener's forms would look like they have no integrations.
     */
    private function warnAboutUnconfiguredListeners(): void
    {
        foreach (Integration::unconfigured() as $class) {
            $this->components->warn(match (true) {
                $class === null => "A closure listens for form submissions, so Canary can't check it",
                Integration::fromPackage($class) => "{$class} comes from a package, so Canary can't check it",
                default => "{$class} has no Canary methods. See ".self::INTEGRATION_DOCS,
            });
        }
    }

    private function settings(): Settings
    {
        return Addon::get('d3creative/statamic-canary')->settings();
    }

    /**
     * What Canary can test in a form, e.g. ['emails', 'MailerLite'].
     */
    private function tests(FormContract $form): array
    {
        return collect($this->emailConfigs($form) ? ['emails'] : [])
            ->merge($this->integrations($form->handle())->map(
                fn (Integration $integration) => $integration->name().($integration->registered() ? '' : ' (not registered)')
            ))
            ->all();
    }

    private function testForm(string $handle, string $to, SiteObject $site): bool
    {
        if (! $form = Form::find($handle)) {
            $this->components->error("No form with the handle {$handle}.");

            return false;
        }

        $emails = $this->emailConfigs($form);
        $integrations = $this->integrations($handle);

        if (! $emails && $integrations->isEmpty()) {
            $this->components->warn("{$handle} has no emails or integrations, so there is nothing to test.");

            return true;
        }

        $passed = ! $emails || $this->sendEmails($form, $emails, $to, $site);

        foreach ($integrations as $integration) {
            $passed = $this->checkIntegration($form, $integration, $to) && $passed;
        }

        return $passed;
    }

    private function sendEmails(FormContract $form, array $emails, string $to, SiteObject $site): bool
    {
        $submission = $form->makeSubmission()->data($this->sampleData($form, $to));

        // Changes the in-memory form only. Never save it, or the real recipients are overwritten.
        $form->email(collect($emails)->map(fn ($email) => array_merge(
            Arr::except($email, ['cc', 'bcc']),
            ['to' => $to, 'subject' => 'TEST: '.($email['subject'] ?? 'form email')]
        ))->all());

        // For forms with a files field, SendEmails chains DeleteTemporaryAttachments,
        // which saves the submission if the form stores them.
        $form->store(false);

        try {
            SendEmails::dispatchSync($submission, $site);
        } catch (Throwable $e) {
            $this->components->error("{$form->handle()}: {$e->getMessage()}");

            return false;
        }

        $this->components->info(count($emails)." email(s) for {$form->handle()} dispatched to {$to}.");

        return true;
    }

    private function checkIntegration(FormContract $form, Integration $integration, string $to): bool
    {
        $label = "{$form->handle()}: {$integration->name()}";

        if (! $integration->registered()) {
            $this->components->error("{$label} isn't listening for form submissions, so it never runs. Check EventServiceProvider.");

            return false;
        }

        $missing = collect($integration->forms()[$form->handle()])
            ->reject(fn ($field) => $form->blueprint()->hasField($field));

        if ($missing->isNotEmpty()) {
            $this->components->error("{$label} reads ".$missing->implode(', ').', which '.($missing->count() === 1 ? "isn't a field" : "aren't fields").' on the form.');

            return false;
        }

        if (method_exists($integration->class, 'canaryCheck')) {
            try {
                $integration->listener()->canaryCheck($form->handle());
            } catch (Throwable $e) {
                $this->components->error("{$label} check failed: {$e->getMessage()}");

                return false;
            }
        }

        if (! $this->option('subscribe')) {
            $this->components->info("{$label} check passed.");

            return true;
        }

        return $this->subscribe($form, $integration, $to, $label);
    }

    private function subscribe(FormContract $form, Integration $integration, string $to, string $label): bool
    {
        if (! $integration->canSubscribe()) {
            $this->components->warn("{$label} check passed. It has no canaryContact and canaryDelete methods, so --subscribe was skipped.");

            return true;
        }

        $listener = $integration->listener();

        try {
            // Deleting an address that was already a contact would remove a real subscription.
            if ($listener->canaryContact($form->handle(), $to)) {
                $this->components->error("{$label}: {$to} is already a contact, so Canary won't add and delete it. Use a --to address that isn't a contact.");

                return false;
            }

            $integration->run((new TestSubmission)->form($form)->data($this->sampleData($form, $to, clientFacing: true)));

            if (! $id = $listener->canaryContact($form->handle(), $to)) {
                $this->components->error("{$label}: the test contact never arrived. Check the site's logs, as the listener's own checks, such as a rate limit, may have stopped it.");

                return false;
            }
        } catch (Throwable $e) {
            $this->components->error("{$label} subscribe failed: {$e->getMessage()}");

            return false;
        }

        try {
            $listener->canaryDelete($form->handle(), $id);
        } catch (Throwable $e) {
            $this->components->error("{$label}: added test contact {$id} but couldn't delete it, so delete it by hand. {$e->getMessage()}");

            return false;
        }

        $this->components->info("{$label} check passed. Test contact added and deleted.");

        return true;
    }

    private function emailConfigs(FormContract $form): array
    {
        $emails = $form->email() ?? [];

        return isset($emails['to']) ? [$emails] : $emails;
    }

    /**
     * Client-facing data ends up in the client's own systems, where Canary means nothing.
     */
    private function sampleData(FormContract $form, string $to, bool $clientFacing = false): array
    {
        return $form->blueprint()->fields()->all()
            ->mapWithKeys(fn (Field $field) => [$field->handle() => $this->sampleValue($field, $to, $clientFacing)])
            ->filter(fn ($value) => ! is_null($value))
            ->all();
    }

    private function sampleValue(Field $field, string $to, bool $clientFacing = false): mixed
    {
        $options = $field->get('options') ?? [];

        return match (true) {
            $field->get('input_type') === 'email' => $to,
            $field->get('input_type') === 'url' => 'https://example.com',
            $field->get('input_type') === 'tel' => '000',
            in_array($field->type(), ['assets', 'files']) => null,
            $field->type() === 'integer' => 1,
            $field->type() === 'toggle' => true,
            $field->type() === 'date' => now()->toDateString(),
            $field->type() === 'checkboxes' => [$this->firstOption($options)],
            filled($options) => $this->firstOption($options),
            $field->type() === 'textarea' => $clientFacing ? 'TEST form submission. Please ignore.' : 'Sent by Canary. Please ignore.',
            default => $clientFacing ? 'TEST form submission' : 'Test',
        };
    }

    private function firstOption(array $options): mixed
    {
        $first = Arr::first($options);

        return match (true) {
            is_array($first) => $first['key'],
            array_is_list($options) => $first,
            default => array_key_first($options),
        };
    }
}
