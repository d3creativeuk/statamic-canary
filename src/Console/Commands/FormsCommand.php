<?php

namespace D3Creative\Canary\Console\Commands;

use D3Creative\Canary\Forms\Integration;
use D3Creative\Canary\Forms\ReadOnlyRequests;
use D3Creative\Canary\Forms\TestSubmission;
use Illuminate\Console\Command;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use ReflectionFunction;
use RuntimeException;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Addons\Settings;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Addon;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Forms\Email;
use Statamic\Forms\SendEmail;
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
                            {--dry-run : Build and check every email and run the API checks, but send nothing}
                            {--scan : List everything that can act on a form submission, without testing anything}
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

    private int $apiChecks = 0;

    /** Integrations found without an API check, so nothing was run against them. */
    private int $notChecked = 0;

    /** Mailers already created this run, by name. */
    private array $mailers = [];

    private array $untested = [];

    /** Forms with no emails whose integrations have no API check. */
    private array $unchecked = [];

    public function handle(): int
    {
        if ($this->option('scan')) {
            return $this->scan();
        }

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

        // A dry run sends nothing, and both of these would.
        foreach (['subscribe', 'now'] as $option) {
            if ($this->option('dry-run') && $this->option($option)) {
                $this->components->error("--dry-run sends nothing, so it can't be used with --{$option}.");

                return self::FAILURE;
            }
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
     * A first look at a site: what listens for form submissions, what could email
     * someone other than the test address, and each form's emails. It builds, sends
     * and calls nothing, and never fails the run.
     */
    private function scan(): int
    {
        $this->components->info('Scanning what can act on a form submission. No email is built or sent, and no listener or API check runs.');

        $this->scanListeners();
        $this->scanRecipients();
        $this->scanForms();
        $this->scanQueue();

        return self::SUCCESS;
    }

    private function scanListeners(): void
    {
        $this->heading('Form listeners');

        $listeners = Integration::listeners();

        foreach ($listeners as $integration) {
            $source = $integration->source();
            $forms = $integration->knownFormHandles();

            $this->components->twoColumnDetail(
                "<fg=bright-white>{$integration->class}</>",
                $integration->registered() ? class_basename($integration->event) : '<fg=red>not registered</>',
            );

            $details = array_filter([
                'Forms' => match (true) {
                    $source->isValidator() => 'none, it rejects submissions rather than sending them',
                    ! $forms => '<fg=yellow>can\'t tell</>',
                    default => implode(', ', $forms).($integration->mayHandleOthers() ? ', <fg=yellow>and maybe others</>' : ''),
                },
                'Services' => implode(', ', $source->services()),
                'Emails' => match (true) {
                    $integration->buildsEmails() => 'built in code, with canaryEmails()',
                    $integration->buildsHiddenEmails() => '<fg=yellow>built in code, which Canary can\'t see without canaryEmails()</>',
                    default => null,
                },
                'Canary methods' => implode(', ', $integration->methods()) ?: 'none',
                'From' => ($package = $integration->package()) ? ($integration->addon() ?? $package) : null,
            ]);

            foreach ($details as $label => $value) {
                $this->components->twoColumnDetail("<fg=gray>{$label}</>", $value);
            }

            $this->newLine();
        }

        if ($closures = Integration::closures()) {
            $this->components->twoColumnDetail('<fg=yellow>'.$closures.' '.Str::plural('closure', $closures).'</>', "Canary can't read what a closure does");
        }

        if ($listeners->isEmpty() && ! $closures) {
            $this->components->twoColumnDetail('None', 'Only the form\'s own email settings act on a submission');
        }
    }

    /**
     * The README's "What could still email someone else": things that change the
     * recipients after Canary has set them.
     */
    private function scanRecipients(): void
    {
        $this->heading('Could email someone else');

        $found = array_filter([
            config('statamic.forms.send_email_job', SendEmail::class) !== SendEmail::class
                ? 'A custom send_email_job, '.config('statamic.forms.send_email_job').', which may choose its own recipients' : null,
            ($address = config('mail.to.address')) ? "mail.to sends every email the site sends to {$address}" : null,
            ...collect(Event::getRawListeners()[MessageSending::class] ?? [])
                ->map(fn ($listener) => 'A MessageSending listener, '.$this->describeListener($listener).', which can add a recipient such as a BCC')
                ->all(),
        ]);

        foreach ($found as $finding) {
            $this->components->warn($finding);
        }

        if (! $found) {
            $this->components->twoColumnDetail('Nothing found', 'A mail provider rule could still add a recipient. Canary can\'t see those');
        }
    }

    /**
     * A listener's class, or for a closure where it's defined, so a package's
     * hook (such as Laravel Nightwatch's) can be told apart from the site's own.
     */
    private function describeListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return Str::before($listener, '@');
        }

        if (is_array($listener)) {
            return is_string($listener[0]) ? $listener[0] : $listener[0]::class;
        }

        $function = new ReflectionFunction($listener);
        $file = Str::after($function->getFileName(), base_path().DIRECTORY_SEPARATOR);
        $package = preg_match('#^vendor/([^/]+/[^/]+)/#', $file, $match) ? " from {$match[1]}" : '';

        return "a closure{$package} ({$file}:{$function->getStartLine()})";
    }

    private function scanForms(): void
    {
        $this->heading('Forms');

        foreach (Form::all() as $form) {
            $emails = $this->emailConfigs($form);

            $notes = collect($emails)
                ->flatMap(fn ($email) => array_filter([
                    isset($email['cc']) ? "cc {$email['cc']}" : null,
                    isset($email['bcc']) ? "bcc {$email['bcc']}" : null,
                    isset($email['mailer']) ? "{$email['mailer']} mailer" : null,
                ]))
                ->unique();

            $listeners = $this->integrations($form->handle())->map(fn (Integration $integration) => class_basename($integration->class));

            $this->components->twoColumnDetail(
                $form->handle(),
                ($emails ? $this->emailKinds($emails, '') : 'no emails in its settings')
                    .($notes->isNotEmpty() ? ', '.$notes->implode(', ') : '')
                    .($listeners->isNotEmpty() ? ', handled by '.$listeners->implode(', ') : ''),
            );
        }
    }

    private function scanQueue(): void
    {
        $this->heading('Queue');

        try {
            $jobs = collect(app('queue.failer')->all());
        } catch (Throwable $e) {
            // Database errors include the connection details and the query, which only add noise here.
            $this->components->twoColumnDetail('Failed jobs', "<fg=yellow>couldn't be read</>: ".Str::before($e->getMessage(), ' (Connection:'));

            return;
        }

        if ($jobs->isEmpty()) {
            $this->components->twoColumnDetail('Failed jobs', 'none');

            return;
        }

        // A failed form email is an enquiry that never arrived. Other failed jobs, such as cache
        // warming, don't affect forms, so they're counted but not warned about.
        $emails = $jobs->filter(fn ($job) => in_array(
            json_decode($job->payload ?? '{}', true)['displayName'] ?? null,
            array_unique([SendEmail::class, config('statamic.forms.send_email_job', SendEmail::class)]),
        ));

        $total = $jobs->count().' failed '.Str::plural('job', $jobs->count());

        if ($emails->isEmpty()) {
            $this->components->twoColumnDetail('Failed jobs', "{$total}, none of them form emails");

            return;
        }

        $dates = $emails->map(fn ($job) => Str::before((string) ($job->failed_at ?? ''), ' '))->filter()->unique()->implode(', ');

        $this->components->warn("{$total}, {$emails->count()} of them ".($emails->count() === 1 ? 'a form email' : 'form emails').($dates ? " ({$dates})" : '')
            .". Each is an email that never arrived. Run php artisan queue:failed to see them, and don't retry one without asking the client, as it goes to the real recipients.");
    }

    private function heading(string $text): void
    {
        $this->newLine();
        $this->line("  <fg=bright-white;options=bold>{$text}</>");
        $this->newLine();
    }

    /**
     * SendEmail jobs go on the default connection, so name it and its queue: a
     * worker started with --queue for another queue never picks them up.
     */
    private function reportQueue(): void
    {
        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: Canary builds and checks every email, but sends none.');

            return;
        }

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

        if ($this->unchecked) {
            $this->components->warn('Nothing was tested in '.implode(', ', $this->unchecked).": no emails, and no API check for their integrations. See ".self::INTEGRATION_DOCS);
        }

        if (! $this->tested) {
            $this->components->error("Canary tested nothing, so it can't say any form works.");

            return false;
        }

        $results = array_filter([
            $this->dispatched ? $this->dispatched.' '.Str::plural('email', $this->dispatched).' '.$this->sentVerb() : null,
            $this->checksPassed ? $this->checksPassed.' integration '.Str::plural('check', $this->checksPassed).' passed' : null,
            $this->notChecked ? $this->notChecked.' '.Str::plural('integration', $this->notChecked).' without an API check' : null,
        ]);

        $this->components->info("Tested {$this->tested} ".Str::plural('form', $this->tested).($results ? ': '.implode(', ', $results) : '').'.');

        // So a dry run can't be mistaken for a full pass.
        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: no emails were sent'.($this->apiChecks ? ', and the API checks only read' : '').". Sending and the queue weren't tested.");

            return true;
        }

        $driver = config('queue.connections.'.config('queue.default').'.driver', config('queue.default'));

        if ($this->dispatched && ! $this->option('now') && $driver !== 'sync') {
            $this->components->info("If an email doesn't arrive, check php artisan queue:failed once a worker has run it.");
        }

        return true;
    }

    private function sentVerb(): string
    {
        return match (true) {
            $this->option('dry-run') => 'ready to send',
            $this->option('now') => 'sent',
            default => 'dispatched',
        };
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
                ->flatMap(fn (Integration $integration) => $this->services($form, $integration)))
            ->all();
    }

    /**
     * Each service a listener checks gets its own entry, e.g. ['Mailchimp', 'Zoho'].
     */
    private function services(FormContract $form, Integration $integration): array
    {
        if (! $integration->hasChecks()) {
            return [$integration->name().$this->notes($integration)];
        }

        try {
            return array_map(fn ($name) => $name.$this->notes($integration), array_keys($integration->checks($form->handle())));
        } catch (Throwable $e) {
            return ["canaryChecks() in {$integration->class} failed: {$e->getMessage()}"];
        }
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

        // Listeners whose forms are unknown, and ones with an else branch that may reach this form.
        return Integration::unknown()
            ->merge(($this->integrations ??= Integration::all())
                ->filter(fn (Integration $integration) => $integration->mayHandleOthers())
                ->map(fn (Integration $integration) => $integration->class))
            ->unique()
            ->map(fn (?string $class) => 'nothing in the form\'s settings, '.($class ?? 'a closure listener').' may handle it')
            ->values()
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
        $before = [$this->checksPassed, $this->apiChecks];

        $passed = ! $emails || $this->sendEmails($form, $emails, $to, $site, $submission);

        foreach ($integrations as $integration) {
            $passed = $this->checkIntegration($form, $integration, $to) && $passed;
        }

        foreach ($integrations->filter(fn (Integration $integration) => $integration->buildsHiddenEmails()) as $integration) {
            $this->components->warn("{$handle}: {$integration->class} builds emails in code, which Canary can't see. Add canaryEmails() to it. See ".self::EMAILS_IN_CODE_DOCS);
        }

        // No emails, and listeners Canary could only find, not check: counting it as
        // tested would report a pass for a form nothing was run against.
        if ($passed && ! $emails && [$this->checksPassed, $this->apiChecks] === $before) {
            $this->tested--;
            $this->unchecked[] = $handle;
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
        if (($real = $this->checkRecipients($form, $emails, $to, $site, $submission)) === null) {
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

        if (! $this->checkMailers($form, $swapped)) {
            return false;
        }

        foreach ($swapped as $email) {
            try {
                $this->render(new Email($submission, $email, $site));
            } catch (Throwable $e) {
                $template = $email['html'] ?? $email['text'] ?? 'the default';
                $this->components->error("{$form->handle()}: the {$template} template failed to render, so none of the form's emails were sent. {$e->getMessage()}");

                return false;
            }
        }

        $merged = count($emails) > $swapped->count() ? ', covering '.count($emails).' that only differ by recipient' : '';

        if ($this->option('dry-run')) {
            $this->dispatched += $swapped->count();
            $this->components->info($swapped->count()." email(s) for {$form->handle()} would go to {$to}"
                .($real ? ' instead of '.implode(', ', $real) : '').$merged.'.');

            return true;
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

        $this->components->info($swapped->count()." email(s) for {$form->handle()} {$this->sentVerb()} to {$to}"
            .($mailers->isNotEmpty() ? ', using the form\'s '.$mailers->implode(', ').' mailer' : '').$merged.'.');

        return true;
    }

    /**
     * The mailers the emails use can be created, such as one whose transport package
     * isn't installed. Rendering doesn't use them, and without --now they'd only fail
     * later in the queue worker. Creating a mailer sends nothing.
     */
    private function checkMailers(FormContract $form, Collection $emails): bool
    {
        foreach ($emails->map(fn ($email) => $email['mailer'] ?? config('mail.default'))->unique() as $mailer) {
            if (isset($this->mailers[$mailer])) {
                continue;
            }

            try {
                // A copy of the site's manager, so transports that packages add with extend(), such as
                // mailersend, are there. Its cached mailers are dropped, so the site's own aren't touched.
                $manager = app('mail.manager');
                $manager = $manager instanceof MailFake ? $manager->manager : $manager;

                (clone $manager)->forgetMailers()->mailer($mailer)->getSymfonyTransport();
            } catch (Throwable $e) {
                $this->components->error("{$form->handle()}: the {$mailer} mailer can't be created, so its emails couldn't be sent. {$e->getMessage()}");

                return false;
            }

            $this->mailers[$mailer] = true;
        }

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
     * recipients, and checks the addresses it would really use. Returns them, or
     * null if one is missing or broken.
     */
    private function checkRecipients(FormContract $form, array $emails, string $to, SiteObject $site, TestSubmission $submission): ?array
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
            // Removed from the test email, but the real one still goes to them, so they're checked too.
            $copies = collect(['cc' => $mail->cc, 'bcc' => $mail->bcc])
                ->flatMap(fn ($addresses, $kind) => collect($addresses)->pluck('address')->filter()->map(fn ($address) => [$kind, $address]));
            $senders = collect($mail->from)->pluck('address')->filter()->whenEmpty(fn ($from) => $from->push(config('mail.from.address')))->filter();

            if ($recipients->isEmpty()) {
                $this->components->error("{$label} has no recipient, so it would never send. Check the setting or rule that gives its address.");
                $passed = false;
            }

            foreach ($recipients->reject(fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL)) as $address) {
                $this->components->error("{$label} would go to \"{$address}\", which isn't an email address.");
                $passed = false;
            }

            // An invalid copy address stops the real email sending, even though the test one has none.
            foreach ($copies->reject(fn ($copy) => filter_var($copy[1], FILTER_VALIDATE_EMAIL)) as [$kind, $address]) {
                $this->components->error("{$label} would {$kind} \"{$address}\", which isn't an email address.");
                $passed = false;
            }

            if ($senders->isEmpty()) {
                $this->components->error("{$label} has no from address, and neither has the mail config.");
                $passed = false;
            }

            // The sample fills email fields with $to, so an auto-reply's address isn't a real one.
            $real = $recipients->reject(fn ($address) => strcasecmp($address, $to) === 0);

            foreach ($real->merge($senders)->merge($copies->pluck(1))->filter(fn ($address) => $this->isPlaceholder($address))->unique() as $address) {
                $this->components->warn("{$label} uses {$address}, which looks like a placeholder. Check the setting it comes from.");
            }

            array_push($checked, ...$real, ...$copies->map(fn ($copy) => "{$copy[0]} {$copy[1]}"));
        }

        if ($checked && $this->output->isVerbose()) {
            $this->components->info("{$form->handle()}: checked, not emailed: ".collect($checked)->unique()->implode(', '));
        }

        return $passed ? array_values(array_unique($checked)) : null;
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

        if ($integration->hasChecks()) {
            $passed = $this->runChecks($form, $integration);

            return $passed && $this->option('subscribe') ? $this->subscribe($form, $integration, $to, $label) : $passed;
        }

        if ($integration->hasApiCheck()) {
            $this->apiChecks++;

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

        // Found isn't checked, so it isn't counted as a pass.
        $integration->hasApiCheck() || $integration->package() !== null
            ? $this->checksPassed++
            : $this->notChecked++;

        return true;
    }

    /**
     * Each service in canaryChecks() passes or fails on its own. A check that returns a
     * string passes with that as a warning, such as "disabled in Globals".
     */
    private function runChecks(FormContract $form, Integration $integration): bool
    {
        try {
            $checks = $integration->checks($form->handle());
        } catch (Throwable $e) {
            $this->components->error("{$form->handle()}: canaryChecks() in {$integration->class} failed: {$e->getMessage()}");

            return false;
        }

        $passed = true;

        foreach ($checks as $name => $check) {
            $label = "{$form->handle()}: {$name}";
            $this->apiChecks++;

            try {
                $result = ReadOnlyRequests::run($check, $blocked);
            } catch (Throwable $e) {
                $this->components->error("{$label} check failed: {$e->getMessage()}");
                $passed = false;

                continue;
            }

            if ($blocked) {
                $this->components->error("{$label} check failed: it made a ".implode(', ', $blocked).' request, which Canary blocked. A check must only read.');
                $passed = false;

                continue;
            }

            $this->checksPassed++;

            is_string($result) && $result !== ''
                ? $this->components->warn("{$label} check passed, with a warning: {$result}")
                : $this->components->info("{$label} check passed.");
        }

        return $passed;
    }

    private function subscribe(FormContract $form, Integration $integration, string $to, string $label): bool
    {
        if (! $integration->canSubscribe()) {
            $this->components->warn("{$label}: --subscribe skipped, as it has no canaryContact and canaryDelete methods.");

            return true;
        }

        if ($reason = $integration->subscribeRefusal($form->handle())) {
            $this->components->warn("{$label}: --subscribe refused, as {$reason}. It runs the whole listener, so the test contact would reach each of them, and Canary can only delete it from one.");

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
