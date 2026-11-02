<?php

namespace D3Creative\Canary\Console\Commands;

use D3Creative\Canary\Forms\Integration;
use D3Creative\Canary\Forms\ReadOnlyRequests;
use D3Creative\Canary\Forms\TestSubmission;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Addons\Settings;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Addon;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Forms\Email;
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
                            {--now : Send the emails straight away instead of through the queue, so mailer errors fail the run}
                            {--subscribe : Also add the test address through each integration, then delete it}';

    private const DOCS = 'https://github.com/d3creativeuk/statamic-canary';

    private const INTEGRATION_DOCS = self::DOCS.'#checking-integrations';

    private const EMAILS_IN_CODE_DOCS = self::DOCS.'#emails-built-in-code';

    /** Domains a missing setting usually falls back to, such as Laravel's hello@example.com. */
    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.org', 'example.net'];

    protected $description = "Send a form's emails to one address and check its integrations, without saving a submission";

    private ?Collection $integrations = null;

    private int $tested = 0;

    private int $dispatched = 0;

    private int $checksPassed = 0;

    private array $untested = [];

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

        $this->warnAboutUnknownListeners();

        if (! $handles = $this->handles($to)) {
            return self::FAILURE;
        }

        $this->reportQueue();
        $this->reportMailer();

        $failed = false;

        foreach ($handles as $handle) {
            $failed = ! $this->testForm($handle, $to, $site) || $failed;
        }

        return $this->summarise() && ! $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * SendEmail jobs go on the default connection, so name it and its queue: a
     * worker started with --queue for another queue never picks them up.
     */
    private function reportQueue(): void
    {
        if ($this->option('now')) {
            $this->components->warn('With --now, emails send straight away instead of through the queue, so this tests the mailer but not a queue worker.');

            return;
        }

        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver", $connection);

        if ($driver === 'sync') {
            $this->components->warn("The queue connection is {$connection}, so this tests the templates and sender but not a queue worker.");

            return;
        }

        $queue = config("queue.connections.{$connection}.queue");
        $where = ($queue ? "the {$queue} queue of " : '')."the {$connection} connection".($driver !== $connection ? " ({$driver})" : '');

        $this->components->info("Emails go on {$where}, so they only send when a queue worker runs.");
    }

    /**
     * The default mailer. A form email with its own mailer is named when it's dispatched.
     */
    private function reportMailer(): void
    {
        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport", $mailer);

        // smtp alone could be a mail provider or a local catcher such as Herd's, so say where it connects.
        $details = array_filter([
            $transport !== $mailer ? $transport : null,
            $transport === 'smtp' ? $this->smtpServer($mailer) : null,
        ]);

        $name = "the {$mailer} mailer".($details ? ' ('.implode(', ', $details).')' : '');

        if (in_array($transport, ['log', 'array'])) {
            $this->components->warn("Emails send with {$name}, so they're never delivered. Set MAIL_MAILER to test delivery.");

            return;
        }

        $this->components->info("Emails send with {$name}.");
    }

    private function smtpServer(string $mailer): ?string
    {
        $host = config("mail.mailers.{$mailer}.host");
        $port = config("mail.mailers.{$mailer}.port");

        return $host ? $host.($port ? ":{$port}" : '') : null;
    }

    /**
     * A run that tested nothing proves nothing, so it fails rather than look like a pass.
     */
    private function summarise(): bool
    {
        if ($this->untested) {
            $this->components->warn('Nothing to test in '.implode(', ', $this->untested).'.');
        }

        if (! $this->tested) {
            $this->components->error("Canary tested nothing, so it can't say any form works.");

            return false;
        }

        $results = array_filter([
            $this->dispatched ? $this->dispatched.' '.Str::plural('email', $this->dispatched).($this->option('now') ? ' sent' : ' dispatched') : null,
            $this->checksPassed ? $this->checksPassed.' integration '.Str::plural('check', $this->checksPassed).' passed' : null,
        ]);

        $this->components->info("Tested {$this->tested} ".Str::plural('form', $this->tested).($results ? ': '.implode(', ', $results) : '').'.');

        $driver = config('queue.connections.'.config('queue.default').'.driver', config('queue.default'));

        if ($this->dispatched && ! $this->option('now') && $driver !== 'sync') {
            $this->components->info("If an email doesn't arrive, check php artisan queue:failed once a worker has run it.");
        }

        return true;
    }

    private function handles(string $to): array
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

        $rows = $forms->mapWithKeys(fn (FormContract $form) => [$form->handle() => [$this->tests($form, $to), $this->hints($form)]]);

        // Forms with nothing to test go last.
        [$testable, $untestable] = $rows->partition(fn ($row) => $row[0]);

        $handles = multiselect(
            label: 'Which forms should Canary test? '.$forms->count().' '.Str::plural('form', $forms->count()).' found.',
            options: $testable->map(fn ($row, $handle) => "{$handle} - ".implode(', ', array_merge(...$row)))
                ->merge($untestable->map(fn ($row, $handle) => "{$handle} - ".($row[1] ? implode(', ', $row[1]) : 'no emails or integrations found')))
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
     * Otherwise these listeners' forms would look like they have no integrations.
     */
    private function warnAboutUnknownListeners(): void
    {
        foreach (Integration::unknown() as $class) {
            $this->components->warn(match (true) {
                $class === null => "A closure listens for form submissions, so Canary can't check it",
                Integration::fromPackage($class) => "{$class} comes from a package, so Canary can't check it",
                default => "Canary can't tell which forms {$class} handles. See ".self::INTEGRATION_DOCS,
            });
        }
    }

    private function settings(): Settings
    {
        return Addon::get('d3creative/statamic-canary')->settings();
    }

    /**
     * What Canary can test in a form, e.g. ['notification email', 'MailerLite'].
     */
    private function tests(FormContract $form, string $to): array
    {
        try {
            $emails = $this->emailKinds($this->emails($form, $this->sampleSubmission($form, $to)), $to);
        } catch (Throwable $e) {
            $emails = $e->getMessage();
        }

        return collect([$emails])
            ->filter()
            ->merge($this->integrations($form->handle())
                ->reject(fn (Integration $integration) => $integration->onlyBuildsEmails())
                ->map(fn (Integration $integration) => $integration->name().$this->notes($integration)))
            ->all();
    }

    /**
     * Things that may send from a form but that Canary can't see, so a form isn't
     * reported as having nothing to test when it might.
     */
    private function hints(FormContract $form): array
    {
        $hidden = $this->integrations($form->handle())
            ->filter(fn (Integration $integration) => $integration->buildsHiddenEmails())
            ->map(fn (Integration $integration) => "emails built in {$integration->class}, add canaryEmails()");

        if ($hidden->isNotEmpty() || $this->emailConfigs($form) || $this->integrations($form->handle())->isNotEmpty()) {
            return $hidden->all();
        }

        return Integration::unknown()
            ->map(fn (?string $class) => 'nothing in the form\'s settings, '.($class ?? 'a closure listener').' may handle it')
            ->all();
    }

    /**
     * An email to a field, such as {{ email }}, goes to the visitor, and so does one
     * built in code for the sample's address. Any other address is the site's own inbox.
     */
    private function emailKinds(array $emails, string $to): ?string
    {
        if (! $emails) {
            return null;
        }

        $kinds = collect($emails)
            ->map(fn ($email) => implode(',', Arr::wrap($email['to'] ?? '')))
            ->map(fn ($address) => str_contains($address, '{{') || Str::contains($address, $to, ignoreCase: true) ? 'auto-reply' : 'notification')
            ->unique()
            ->sortBy(fn ($kind) => $kind === 'auto-reply')
            ->implode(' and ');

        return $kinds.(count($emails) === 1 ? ' email' : ' emails');
    }

    private function notes(Integration $integration): string
    {
        $notes = array_filter([
            $integration->registered() ? null : 'not registered',
            // A package's listener can't be given one, so it isn't something to fix.
            $integration->hasApiCheck() || $integration->package() ? null : 'no API check',
        ]);

        return $notes ? ' ('.implode(', ', $notes).')' : '';
    }

    private function testForm(string $handle, string $to, SiteObject $site): bool
    {
        if (! $form = Form::find($handle)) {
            $this->components->error("No form with the handle {$handle}.");

            return false;
        }

        $integrations = $this->integrations($handle);
        $submission = $this->sampleSubmission($form, $to);

        try {
            $emails = $this->emails($form, $submission);
        } catch (Throwable $e) {
            $this->tested++;
            $this->components->error("{$handle}: {$e->getMessage()}");

            return false;
        }

        if (! $emails && $integrations->isEmpty()) {
            $this->untested[] = $handle;

            foreach ($this->hints($form) as $hint) {
                $this->components->warn("{$handle}: {$hint}. See ".self::EMAILS_IN_CODE_DOCS);
            }

            if (! $this->hints($form)) {
                $this->components->warn("{$handle} has no emails or integrations, so there is nothing to test.");
            }

            return true;
        }

        $this->tested++;

        $passed = ! $emails || $this->sendEmails($form, $emails, $to, $site, $submission);

        foreach ($integrations as $integration) {
            $passed = $this->checkIntegration($form, $integration, $to) && $passed;
        }

        foreach ($integrations->filter(fn (Integration $integration) => $integration->buildsHiddenEmails()) as $integration) {
            $this->components->warn("{$handle}: {$integration->class} builds emails in code, which Canary can't see. Add canaryEmails() to it. See ".self::EMAILS_IN_CODE_DOCS);
        }

        return $passed;
    }

    /**
     * The form's emails. Ones a listener builds in code replace the form's settings,
     * as they do on a real submission.
     */
    private function emails(FormContract $form, TestSubmission $submission): array
    {
        $built = $this->integrations($form->handle())
            ->filter(fn (Integration $integration) => $integration->buildsEmails())
            ->flatMap(function (Integration $integration) use ($form, $submission) {
                try {
                    $emails = ReadOnlyRequests::run(fn () => $integration->emails($form->handle(), $submission), $blocked);
                } catch (Throwable $e) {
                    throw new RuntimeException("canaryEmails() in {$integration->class} failed: {$e->getMessage()}", previous: $e);
                }

                if ($blocked) {
                    throw new RuntimeException("canaryEmails() in {$integration->class} made a ".implode(', ', $blocked).' request, which Canary blocked. It must only build the emails.');
                }

                return $emails;
            })
            ->all();

        return $built ?: $this->emailConfigs($form);
    }

    private function sendEmails(FormContract $form, array $emails, string $to, SiteObject $site, TestSubmission $submission): bool
    {
        // Checked before the swap below, which would hide a missing or broken address.
        if (! $this->checkRecipients($form, $emails, $to, $site, $submission)) {
            return false;
        }

        // Emails that only differ by recipient are the same once each goes to $to.
        $swapped = collect($emails)
            ->map(fn ($email) => array_merge(
                Arr::except($email, ['cc', 'bcc']),
                ['to' => $to, 'subject' => 'TEST: '.($email['subject'] ?? 'form email')]
            ))
            ->unique(fn ($email) => json_encode($email))
            ->values();

        foreach ($swapped as $email) {
            try {
                $this->render(new Email($submission, $email, $site));
            } catch (Throwable $e) {
                $template = $email['html'] ?? $email['text'] ?? 'the default';
                $this->components->error("{$form->handle()}: the {$template} template failed to render, so none of the form's emails were sent. {$e->getMessage()}");

                return false;
            }
        }

        // Changes the in-memory form only. Never save it, or the real recipients are overwritten.
        $form->email($swapped->all());

        // For forms with a files field, SendEmails chains DeleteTemporaryAttachments,
        // which saves the submission if the form stores them.
        $form->store(false);

        $queue = config('queue.default');

        try {
            if ($this->option('now')) {
                config(['queue.default' => 'sync']);
            }

            SendEmails::dispatchSync($submission, $site);
        } catch (Throwable $e) {
            $this->components->error("{$form->handle()}: {$e->getMessage()}");

            return false;
        } finally {
            config(['queue.default' => $queue]);
        }

        $this->dispatched += $swapped->count();

        $mailers = $swapped->pluck('mailer')->filter()->unique();
        $merged = count($emails) - $swapped->count();

        $this->components->info($swapped->count()." email(s) for {$form->handle()} ".($this->option('now') ? 'sent' : 'dispatched')." to {$to}"
            .($mailers->isNotEmpty() ? ', using the form\'s '.$mailers->implode(', ').' mailer' : '')
            .($merged ? ', covering '.count($emails).' that only differ by recipient' : '').'.');

        return true;
    }

    /**
     * Mailable::render() goes through the site's own mailer, so a mailer that can't be
     * created would look like a broken template. Rendering doesn't need it. The email
     * builds again here, so it needs its own instance.
     */
    private function render(Email $mail): void
    {
        // Only take the site's mailer back if it already exists, as making it is what could fail.
        $previous = app()->resolved('mailer') ? app('mailer') : null;

        app()->instance('mailer', new Mailer('canary', app('view'), new ArrayTransport));

        try {
            $mail->render();
        } finally {
            $previous ? app()->instance('mailer', $previous) : app()->forgetInstance('mailer');
        }
    }

    /**
     * Builds each email as it's configured, before the test address replaces its
     * recipients, and checks the addresses it would really use.
     */
    private function checkRecipients(FormContract $form, array $emails, string $to, SiteObject $site, TestSubmission $submission): bool
    {
        $passed = true;
        $checked = [];

        foreach ($emails as $config) {
            try {
                $mail = new Email($submission, $config, $site);
                $mail->build();
            } catch (Throwable $e) {
                $this->components->error("{$form->handle()}: an email couldn't be built. {$e->getMessage()}");
                $passed = false;

                continue;
            }

            $label = "{$form->handle()}: the \"{$mail->subject}\" email";
            $recipients = collect($mail->to)->pluck('address')->filter();
            $senders = collect($mail->from)->pluck('address')->filter()->whenEmpty(fn ($from) => $from->push(config('mail.from.address')))->filter();

            if ($recipients->isEmpty()) {
                $this->components->error("{$label} has no recipient, so it would never send. Check the setting or rule that gives its address.");
                $passed = false;
            }

            foreach ($recipients->reject(fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL)) as $address) {
                $this->components->error("{$label} would go to \"{$address}\", which isn't an email address.");
                $passed = false;
            }

            if ($senders->isEmpty()) {
                $this->components->error("{$label} has no from address, and neither has the mail config.");
                $passed = false;
            }

            // The sample fills email fields with $to, so an auto-reply's address isn't a real one.
            $real = $recipients->reject(fn ($address) => strcasecmp($address, $to) === 0);

            foreach ($real->merge($senders)->filter(fn ($address) => $this->isPlaceholder($address))->unique() as $address) {
                $this->components->warn("{$label} uses {$address}, which looks like a placeholder. Check the setting it comes from.");
            }

            array_push($checked, ...$real);
        }

        if ($checked && $this->output->isVerbose()) {
            $this->components->info("{$form->handle()}: checked, not emailed: ".collect($checked)->unique()->implode(', '));
        }

        return $passed;
    }

    private function isPlaceholder(string $address): bool
    {
        $domain = Str::lower(Str::after($address, '@'));

        return in_array($domain, self::PLACEHOLDER_DOMAINS) || str_ends_with($domain, '.invalid');
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

        // Its emails were tested above, and there's nothing else to check.
        if ($integration->onlyBuildsEmails()) {
            return true;
        }

        if ($integration->hasApiCheck()) {
            try {
                ReadOnlyRequests::run(fn () => $integration->listener()->canaryCheck($form->handle()), $blocked);
            } catch (Throwable $e) {
                $this->components->error("{$label} check failed: {$e->getMessage()}");

                return false;
            }

            // Fails even if the listener caught the exception and carried on.
            if ($blocked) {
                $this->components->error("{$label} check failed: it made a ".implode(', ', $blocked).' request, which Canary blocked. A check must only read.');

                return false;
            }
        }

        if ($this->option('subscribe')) {
            return $this->subscribe($form, $integration, $to, $label);
        }

        match (true) {
            $integration->hasApiCheck() => $this->components->info("{$label} check passed."),
            // The site can't add methods to a package's listener, so there's nothing to point it at.
            $integration->package() !== null => $this->components->info("{$label} check passed. ".($integration->addon() ? "It's an addon" : 'It comes from a package').', so only its registration and fields were checked.'),
            default => $this->components->warn("{$label} found, but there's no API check. See ".self::INTEGRATION_DOCS),
        };

        $this->checksPassed++;

        return true;
    }

    private function subscribe(FormContract $form, Integration $integration, string $to, string $label): bool
    {
        if (! $integration->canSubscribe()) {
            $this->components->warn("{$label}: --subscribe skipped, as it has no canaryContact and canaryDelete methods.");

            return true;
        }

        $listener = $integration->listener();

        try {
            // Deleting an address that was already a contact would remove a real subscription.
            if ($listener->canaryContact($form->handle(), $to)) {
                $this->components->error("{$label}: {$to} is already a contact, so Canary won't add and delete it. Use a --to address that isn't a contact.");

                return false;
            }

            $queued = $integration->run($this->sampleSubmission($form, $to, clientFacing: true));

            if ($queued) {
                $this->components->error("{$label}: the listener queued a job on the ".implode(', ', $queued).' connection, so the test contact arrives after Canary has checked. Once a worker runs it, delete '.$to.' from the account by hand.');

                return false;
            }

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
        $this->checksPassed++;

        return true;
    }

    private function emailConfigs(FormContract $form): array
    {
        $emails = $form->email() ?? [];

        return isset($emails['to']) ? [$emails] : $emails;
    }

    /**
     * A submission that can't be saved, even by a listener's own code.
     */
    private function sampleSubmission(FormContract $form, string $to, bool $clientFacing = false): TestSubmission
    {
        return (new TestSubmission)->form($form)->data($this->sampleData($form, $to, $clientFacing));
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
            // Every option, so routing rules that match on them all fire. Real services get one.
            $field->type() === 'checkboxes' || ($field->get('multiple') && filled($options)) => $clientFacing ? [$this->firstOption($options)] : $this->optionKeys($options),
            filled($options) => $this->firstOption($options),
            $field->type() === 'textarea' => $clientFacing ? 'TEST form submission. Please ignore.' : 'Sent by Canary. Please ignore.',
            default => $clientFacing ? 'TEST form submission' : 'Test',
        };
    }

    private function firstOption(array $options): mixed
    {
        return Arr::first($this->optionKeys($options));
    }

    /**
     * The three formats from HasSelectOptions::getOptions(): a list, key: label
     * pairs, and ['key' => …, 'value' => …] items.
     */
    private function optionKeys(array $options): array
    {
        return match (true) {
            is_array(Arr::first($options)) => array_column($options, 'key'),
            array_is_list($options) => $options,
            default => array_keys($options),
        };
    }
}
