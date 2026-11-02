<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Tests\Fixtures\MailchimpWithZohoLead;
use D3Creative\Canary\Tests\Fixtures\MultiServiceCrm;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\SubmissionCreated;

class SeveralServicesTest extends TestCase
{
    private const EMAIL = ['to' => 'client@outer.test', 'subject' => 'New contact'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Mail::fake();
        MultiServiceCrm::reset();
        MailchimpWithZohoLead::$handled = false;
    }

    #[Test]
    public function the_picker_lists_each_service()
    {
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 1 form found.', ['contact'], [
                'contact', 'contact - notification email, Mailchimp, Zoho',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->assertSuccessful();
    }

    #[Test]
    public function each_service_is_checked_and_a_returned_string_warns()
    {
        MultiServiceCrm::$zoho = 'warn';
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('contact: Mailchimp check passed.')
            ->expectsOutputToContain('contact: Zoho check passed, with a warning: disabled in Globals')
            ->expectsOutputToContain('Tested 1 form: 1 email dispatched, 2 integration checks passed.')
            ->assertSuccessful();
    }

    #[Test]
    public function one_failing_service_fails_the_run_and_the_other_is_still_reported()
    {
        MultiServiceCrm::$zoho = 'fail';
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('contact: Mailchimp check passed.')
            ->expectsOutputToContain('contact: Zoho check failed: Zoho Access Token is invalid')
            ->assertFailed();
    }

    #[Test]
    public function a_service_check_cant_write()
    {
        Http::fake();
        MultiServiceCrm::$zoho = 'write';
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('contact: Zoho check failed: Canary blocked a POST request to accounts.zoho.test')
            ->assertFailed();

        Http::assertNothingSent();
    }

    #[Test]
    public function subscribe_is_refused_for_a_listener_with_several_checks()
    {
        Event::listen(SubmissionCreated::class, MultiServiceCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain('--subscribe refused, as it has checks for Mailchimp and Zoho.')
            ->assertSuccessful();

        $this->assertFalse(MultiServiceCrm::$handled);
    }

    #[Test]
    public function subscribe_is_refused_when_the_code_names_another_service()
    {
        Event::listen(SubmissionCreated::class, MailchimpWithZohoLead::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain('contact: Mailchimp: --subscribe refused, as its code names Mailchimp and Zoho.')
            ->assertSuccessful();

        $this->assertFalse(MailchimpWithZohoLead::$handled);
    }
}
