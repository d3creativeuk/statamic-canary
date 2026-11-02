<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Tests\Fixtures\BuildsQuoteEmails;
use D3Creative\Canary\Tests\Fixtures\HandleFormSubmissions;
use D3Creative\Canary\Tests\Fixtures\MultiServiceCrm;
use D3Creative\Canary\Tests\Fixtures\SplitsB2bForms;
use D3Creative\Canary\Tests\Fixtures\ValidateRecaptcha;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Forms\SendEmail;

class ScanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.failed.driver' => 'null']);
        Mail::fake();
        Queue::fake();
        HandleFormSubmissions::reset();
        MultiServiceCrm::reset();
    }

    #[Test]
    public function it_lists_every_form_listener_and_what_it_does()
    {
        // canaryEmails() would fail the run if the scan called it.
        HandleFormSubmissions::$whileBuilding = fn () => throw new \RuntimeException('Called during a scan.');
        Event::listen(SubmissionCreated::class, HandleFormSubmissions::class);
        Event::listen(SubmissionCreated::class, BuildsQuoteEmails::class);
        Event::listen(SubmissionCreated::class, SplitsB2bForms::class);
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        Event::listen(FormSubmitted::class, ValidateRecaptcha::class);
        Event::listen(FormSubmitted::class, function () {});
        $this->makeForm('enquiry');
        $this->makeForm('b2b_contact', [['to' => 'sales@outer.test', 'bcc' => 'archive@outer.test', 'mailer' => 'postmark']]);

        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('No email is built or sent, and no listener or API check runs.')
            ->expectsOutputToContain('built in code, with canaryEmails()')
            ->expectsOutputToContain("built in code, which Canary can't see without canaryEmails()")
            ->expectsOutputToContain('b2b_contact, and maybe others')
            ->expectsOutputToContain('Mailchimp, Zoho')
            ->expectsOutputToContain('canaryForms, canaryChecks, canaryContact, canaryDelete')
            ->expectsOutputToContain('none, it rejects submissions rather than sending them')
            ->expectsOutputToContain('1 closure')
            ->expectsOutputToContain('notification email, bcc archive@outer.test, postmark mailer, handled by SplitsB2bForms')
            ->expectsOutputToContain('no emails in its settings, handled by HandleFormSubmissions')
            ->assertSuccessful();

        $this->assertFalse(HandleFormSubmissions::$handled);
        $this->assertFalse(MultiServiceCrm::$handled);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_warns_about_anything_that_could_email_someone_else()
    {
        config(['mail.to.address' => 'catch@outer.test', 'statamic.forms.send_email_job' => 'App\Jobs\SendFormEmail']);
        Event::listen(MessageSending::class, 'App\Listeners\AddBcc@handle');
        Event::listen(MessageSending::class, function () {});

        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('A custom send_email_job, App\Jobs\SendFormEmail, which may choose its own recipients.')
            ->expectsOutputToContain('mail.to sends every email the site sends to catch@outer.test.')
            ->expectsOutputToContain('A MessageSending listener, App\Listeners\AddBcc, which can add a recipient such as a BCC.')
            ->expectsOutputToContain('A MessageSending listener, a closure (')
            ->assertSuccessful();
    }

    #[Test]
    public function it_leaves_out_app_listeners_that_arent_for_forms()
    {
        $this->app->useAppPath(dirname(__DIR__).'/Fixtures/App');
        (new \ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'D3Creative\\Canary\\Tests\\Fixtures\\App\\');

        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('ForgottenCrm')
            ->doesntExpectOutputToContain('PlainListener')
            ->assertSuccessful();
    }

    #[Test]
    public function it_picks_out_failed_form_emails()
    {
        $this->failedJobs(['Statamic\\Console\\Commands\\StaticWarmJob', SendEmail::class, 'Statamic\\Listeners\\GeneratePresetImageManipulations']);

        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('3 failed jobs, 1 of them a form email (2025-04-09). Each is an email that never arrived.')
            ->assertSuccessful();
    }

    #[Test]
    public function other_failed_jobs_arent_warned_about()
    {
        $this->failedJobs(['Statamic\\Console\\Commands\\StaticWarmJob', 'Statamic\\Console\\Commands\\StaticWarmJob']);

        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('2 failed jobs, none of them form emails')
            ->doesntExpectOutputToContain('never arrived')
            ->assertSuccessful();
    }

    /**
     * Stands in for the failed job store, with one job of each class, failed on 9 April 2025.
     */
    private function failedJobs(array $classes): void
    {
        $this->app->instance('queue.failer', new class($classes) extends NullFailedJobProvider
        {
            public function __construct(private array $classes) {}

            public function all()
            {
                return array_map(fn ($class) => (object) [
                    'payload' => json_encode(['displayName' => $class]),
                    'failed_at' => '2025-04-09 05:48:08',
                ], $this->classes);
            }
        });
    }

    #[Test]
    public function it_says_when_nothing_could_email_someone_else()
    {
        $this->artisan('canary:forms', ['--scan' => true])
            ->expectsOutputToContain('Nothing found')
            ->doesntExpectOutputToContain('send_email_job')
            ->expectsOutputToContain('Failed jobs')
            ->assertSuccessful();
    }
}
