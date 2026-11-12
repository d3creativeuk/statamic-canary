<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Tests\Fixtures\App\Listeners\PlainListener;
use D3Creative\Canary\Tests\Fixtures\BuildsQuoteEmails;
use D3Creative\Canary\Tests\Fixtures\HandleFormSubmissions;
use D3Creative\Canary\Tests\Fixtures\SplitsB2bForms;
use D3Creative\Canary\Tests\Fixtures\ValidateRecaptcha;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form;
use Statamic\Forms\Email;
use Statamic\Forms\SendEmail;
use Throwable;

class EmailsInCodeTest extends TestCase
{
    private const ENQUIRY_FIELDS = [
        ['handle' => 'full_name', 'field' => ['type' => 'text']],
        ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email']],
        ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => ['events' => 'Events', 'sales' => 'Sales']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Mail::fake();
        HandleFormSubmissions::reset();
        Event::listen(SubmissionCreated::class, HandleFormSubmissions::class);
    }

    #[Test]
    public function emails_built_in_code_only_reach_the_test_address()
    {
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com', '-v' => true])
            ->expectsOutputToContain('enquiry: checked, not emailed: events@outer.test, sales@outer.test')
            ->expectsOutputToContain('2 email(s) for enquiry dispatched to me@example.com, covering 3 that only differ by recipient.')
            ->expectsOutputToContain('Tested 1 form: 2 emails dispatched.')
            ->assertSuccessful();

        Mail::assertSent(Email::class, 2);
        Mail::assertSent(Email::class, function (Email $mail) {
            $mail->build();

            return $mail->hasTo('me@example.com')
                && ! $mail->hasTo('events@outer.test')
                && ! $mail->hasTo('sales@outer.test')
                && str_starts_with($mail->subject, 'TEST: ');
        });
    }

    #[Test]
    public function the_listener_itself_never_runs()
    {
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS, store: true);
        Queue::fake();

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com'])
            ->assertSuccessful();

        $this->assertFalse(HandleFormSubmissions::$handled);
        $this->assertCount(0, Form::find('enquiry')->submissions());
        // SendEmails chains the jobs, so the second waits behind the first.
        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => count($job->chained) === 1);
    }

    #[Test]
    public function the_picker_lists_emails_built_in_code_without_naming_the_listener()
    {
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 1 form found.', ['enquiry'], [
                'enquiry', 'enquiry - notification and auto-reply emails',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->doesntExpectOutputToContain('HandleFormSubmissions')
            ->assertSuccessful();
    }

    #[Test]
    public function they_replace_the_forms_own_emails()
    {
        $this->makeForm('download', [['to' => 'old@outer.test', 'subject' => 'Old email', 'html' => 'emails/contact']], self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com'])
            ->assertSuccessful();

        Mail::assertSent(Email::class, 1);
        Mail::assertSent(Email::class, fn (Email $mail) => $mail->getConfig()['subject'] === 'TEST: Download requested');
    }

    #[Test]
    public function an_empty_recipient_fails_before_anything_is_sent()
    {
        HandleFormSubmissions::$downloadTo = '';
        $this->makeForm('download', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('download: the "Download requested" email has no recipient, so it would never send.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_placeholder_recipient_warns()
    {
        HandleFormSubmissions::$downloadTo = 'hello@example.com';
        $this->makeForm('download', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@outer.test'])
            ->expectsOutputToContain('download: the "Download requested" email uses hello@example.com, which looks like a placeholder.')
            ->assertSuccessful();
    }

    #[Test]
    public function a_failing_builder_fails_the_form_and_shows_in_the_picker()
    {
        HandleFormSubmissions::$whileBuilding = fn () => throw new \RuntimeException('No rules set up.');
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 1 form found.', ['enquiry'], [
                'enquiry', 'enquiry - canaryEmails() in '.HandleFormSubmissions::class.' failed: No rules set up.',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->expectsOutputToContain('enquiry: canaryEmails() in '.HandleFormSubmissions::class.' failed: No rules set up.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_builder_cant_write_through_the_http_client_even_if_it_catches_the_error()
    {
        Http::fake();
        HandleFormSubmissions::$whileBuilding = function () {
            Http::get('https://api.outer.test/rules');

            try {
                Http::post('https://accounts.outer.test/oauth/token');
            } catch (Throwable) {
                // A listener that swallows the error must still fail.
            }
        };
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('made a POST accounts.outer.test request, which Canary blocked.')
            ->assertFailed();

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_listener_building_emails_canary_cant_see_is_named()
    {
        Event::forget(SubmissionCreated::class);
        Event::listen(SubmissionCreated::class, BuildsQuoteEmails::class);
        $this->makeForm('quote');

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 1 form found.', ['quote'], [
                'quote', 'quote - BuildsQuoteEmails (no API check), emails built in '.BuildsQuoteEmails::class.', add canaryEmails()',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->expectsOutputToContain('quote: '.BuildsQuoteEmails::class." builds emails in code, which Canary can't see. Add canaryEmails() to it.")
            // Canary saw no emails and has no check for the listener, so nothing ran.
            ->expectsOutputToContain('Nothing was tested in quote')
            ->assertFailed();
    }

    #[Test]
    public function a_form_a_listener_may_handle_isnt_reported_as_empty()
    {
        Event::forget(SubmissionCreated::class);
        Event::listen(SubmissionCreated::class, PlainListener::class);
        $this->makeForm('contact');
        $this->makeForm('quote', [['to' => 'quotes@outer.test', 'html' => 'emails/contact']]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['contact', 'quote'], [
                'contact', 'quote',
                'quote - notification email',
                "contact - nothing in the form's settings, ".PlainListener::class.' may handle it',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->expectsOutputToContain("contact: nothing in the form's settings, ".PlainListener::class.' may handle it.')
            ->assertSuccessful();
    }

    #[Test]
    public function a_form_an_else_branch_may_reach_isnt_reported_as_empty()
    {
        Event::forget(SubmissionCreated::class);
        Event::listen(SubmissionCreated::class, SplitsB2bForms::class);
        $this->makeForm('b2b_contact');
        $this->makeForm('widget');

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['b2b_contact'], [
                'b2b_contact', 'widget',
                'b2b_contact - SplitsB2bForms (no API check)',
                "widget - nothing in the form's settings, ".SplitsB2bForms::class.' may handle it',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            // The listener has no API check and the form no emails, so the run itself tests nothing.
            ->expectsOutputToContain('Nothing was tested in b2b_contact')
            ->assertFailed();
    }

    #[Test]
    public function validators_arent_warned_about()
    {
        Event::listen(FormSubmitted::class, ValidateRecaptcha::class);
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com'])
            ->doesntExpectOutputToContain('ValidateRecaptcha')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_checks_everything_but_sends_nothing()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->makeForm('enquiry', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: Canary builds and checks every email, but sends none.')
            ->expectsOutputToContain('2 email(s) for enquiry would go to me@example.com instead of events@outer.test, sales@outer.test, covering 3 that only differ by recipient.')
            ->expectsOutputToContain('Tested 1 form: 2 emails ready to send.')
            ->expectsOutputToContain("Dry run: no emails were sent. Sending and the queue weren't tested.")
            ->doesntExpectOutputToContain('queue:failed')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertFalse(HandleFormSubmissions::$handled);
        // The swap is only made when sending, so the in-memory form keeps its own settings.
        $this->assertEmpty(Form::find('enquiry')->email());
    }

    #[Test]
    public function a_dry_run_still_fails_on_a_broken_email()
    {
        HandleFormSubmissions::$downloadTo = '';
        $this->makeForm('download', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com', '--dry-run' => true])
            ->expectsOutputToContain('has no recipient')
            ->assertFailed();
    }

    #[Test]
    public function a_dry_run_cant_subscribe_or_send_now()
    {
        $this->makeForm('download', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com', '--dry-run' => true, '--subscribe' => true])
            ->expectsOutputToContain("--dry-run sends nothing, so it can't be used with --subscribe.")
            ->assertFailed();

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com', '--dry-run' => true, '--now' => true])
            ->expectsOutputToContain("--dry-run sends nothing, so it can't be used with --now.")
            ->assertFailed();
    }

    #[Test]
    public function now_sends_the_emails_without_the_queue()
    {
        config(['queue.default' => 'redis']);
        $this->makeForm('download', fields: self::ENQUIRY_FIELDS);

        $this->artisan('canary:forms', ['forms' => ['download'], '--to' => 'me@example.com', '--now' => true])
            ->expectsOutputToContain('With --now, emails send straight away instead of through the queue')
            ->expectsOutputToContain('1 email(s) for download sent to me@example.com.')
            ->doesntExpectOutputToContain('queue:failed')
            ->assertSuccessful();

        Mail::assertSent(Email::class, 1);
        $this->assertSame('redis', config('queue.default'));
    }
}
