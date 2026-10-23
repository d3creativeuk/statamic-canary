<?php

namespace D3Creative\Canary\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Forms\SendEmails;
use Statamic\Sites\Site as SiteObject;
use Throwable;

use function Laravel\Prompts\multiselect;

class FormsCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'canary:forms
                            {forms?* : Handles of the forms to test. Defaults to canary.forms, or a list to pick from}
                            {--to= : The address that receives every test email. Defaults to canary.to (CANARY_TO)}
                            {--site= : Handle of the site to send as. Defaults to the default site}';

    protected $description = "Send a form's emails to one address without saving a submission or running its integrations";

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
        if ($handles = $this->argument('forms') ?: Arr::wrap(config('canary.forms'))) {
            return $handles;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No forms to test. Pass form handles as arguments or list them in canary.forms in config/canary.php.');

            return [];
        }

        $forms = Form::all();

        if ($forms->isEmpty()) {
            $this->components->error('No forms found.');

            return [];
        }

        return multiselect(
            label: 'Which forms should Canary test?',
            options: $forms->mapWithKeys(fn (FormContract $form) => [
                $form->handle() => "{$form->title()} ({$form->handle()})".($this->emailConfigs($form) ? '' : ' - no emails'),
            ])->all(),
            required: true,
            hint: 'To skip this list, pass handles as arguments or set canary.forms.',
        );
    }

    private function testForm(string $handle, string $to, SiteObject $site): bool
    {
        if (! $form = Form::find($handle)) {
            $this->components->error("No form with the handle {$handle}.");

            return false;
        }

        if (! $emails = $this->emailConfigs($form)) {
            $this->components->warn("{$handle} has no emails configured, so there is nothing to send.");

            return true;
        }

        $submission = $form->makeSubmission()->data(
            $form->blueprint()->fields()->all()
                ->mapWithKeys(fn (Field $field) => [$field->handle() => $this->sampleValue($field, $to)])
                ->filter(fn ($value) => ! is_null($value))
                ->all()
        );

        // Changes the in-memory form only. Never save it, or the real recipients are overwritten.
        $form->email(collect($emails)->map(fn ($email) => array_merge(
            Arr::except($email, ['cc', 'bcc']),
            ['to' => $to, 'subject' => 'TEST: '.($email['subject'] ?? 'form email')]
        ))->all());

        try {
            SendEmails::dispatchSync($submission, $site);
        } catch (Throwable $e) {
            $this->components->error("{$handle}: {$e->getMessage()}");

            return false;
        }

        $this->components->info(count($emails)." email(s) for {$handle} dispatched to {$to}.");

        return true;
    }

    private function emailConfigs(FormContract $form): array
    {
        $emails = $form->email() ?? [];

        return isset($emails['to']) ? [$emails] : $emails;
    }

    private function sampleValue(Field $field, string $to): mixed
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
            $field->type() === 'textarea' => 'Sent by Canary. Please ignore.',
            default => 'Test',
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
