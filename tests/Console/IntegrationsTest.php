<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Tests\Fixtures\App\Listeners\PlainListener;
use D3Creative\Canary\Tests\Fixtures\FakeCrm;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form;

class IntegrationsTest extends TestCase
{
    private const EMAIL = ['to' => 'client@example.com', 'subject' => 'New contact'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Mail::fake();
        FakeCrm::reset();
        Event::listen(FormSubmitted::class, FakeCrm::class);
    }

    #[Test]
    public function the_picker_says_what_each_form_tests()
    {
        $this->makeForm('contact', [self::EMAIL]);
        $this->makeForm('newsletter');
        $this->makeForm('quote', [self::EMAIL]);
        $this->makeForm('old');
        $this->useListenersIn('App');

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 4 forms found.', ['quote'], [
                'contact', 'newsletter', 'quote', 'old',
                'contact - emails, FakeCRM',
                'newsletter - FakeCRM, ForgottenCRM (not registered)',
                'quote - emails',
                'old - no emails or integrations found',
            ])
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->assertSuccessful();
    }

    #[Test]
    public function it_sends_the_emails_and_checks_the_integration()
    {
        $this->makeForm('contact', [self::EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('1 email(s) for contact dispatched to me@example.com.')
            ->expectsOutputToContain('contact: FakeCRM check passed.')
            ->assertSuccessful();

        $this->assertSame(['contact'], FakeCrm::$checked);
        $this->assertSame([], FakeCrm::$received);
    }

    #[Test]
    public function a_form_with_only_an_integration_is_still_tested()
    {
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('newsletter: FakeCRM check passed.')
            ->doesntExpectOutputToContain('nothing to test')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_failed_check_fails_the_run()
    {
        FakeCrm::$failCheck = 'Unauthenticated.';
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('newsletter: FakeCRM check failed: Unauthenticated.')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_field_fails_before_the_api_is_called()
    {
        $this->makeForm('newsletter', fields: [['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email']]]);

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("newsletter: FakeCRM reads business_email, which isn't a field on the form.")
            ->assertFailed();

        $this->assertSame([], FakeCrm::$checked);
    }

    #[Test]
    public function an_unregistered_listener_fails_the_run()
    {
        $this->makeForm('newsletter');
        $this->useListenersIn('App');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('newsletter: FakeCRM check passed.')
            ->expectsOutputToContain("newsletter: ForgottenCRM isn't listening for form submissions, so it never runs. Check EventServiceProvider.")
            ->assertFailed();
    }

    #[Test]
    public function subscribe_adds_the_test_contact_then_deletes_it()
    {
        $this->makeForm('newsletter', store: true);
        $otherListenerRan = false;
        Event::listen(FormSubmitted::class, function () use (&$otherListenerRan) {
            $otherListenerRan = true;
        });
        Event::listen(SubmissionCreated::class, function () use (&$otherListenerRan) {
            $otherListenerRan = true;
        });

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain('newsletter: FakeCRM check passed. Test contact added and deleted.')
            ->assertSuccessful();

        $this->assertCount(1, FakeCrm::$received);
        $this->assertSame('me@example.com', FakeCrm::$received[0]['business_email']);
        $this->assertSame('TEST form submission', FakeCrm::$received[0]['full_name']);
        $this->assertSame('TEST form submission. Please ignore.', FakeCrm::$received[0]['message_text']);
        $this->assertSame(['contact-1'], FakeCrm::$deleted);
        $this->assertSame([], FakeCrm::$contacts);

        // Only that listener runs, and the submission it saves goes nowhere.
        $this->assertFalse($otherListenerRan);
        $this->assertCount(0, Form::find('newsletter')->submissions());
    }

    #[Test]
    public function subscribe_wont_touch_an_existing_contact()
    {
        FakeCrm::$contacts = ['me@example.com' => 'real-contact'];
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain("newsletter: FakeCRM: me@example.com is already a contact, so Canary won't add and delete it.")
            ->assertFailed();

        $this->assertSame([], FakeCrm::$received);
        $this->assertSame(['me@example.com' => 'real-contact'], FakeCrm::$contacts);
    }

    #[Test]
    public function subscribe_fails_when_the_contact_never_arrives()
    {
        FakeCrm::$dropSubmissions = true;
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain('newsletter: FakeCRM: the test contact never arrived.')
            ->assertFailed();
    }

    #[Test]
    public function subscribe_says_which_contact_to_delete_when_cleanup_fails()
    {
        FakeCrm::$failDelete = true;
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain("newsletter: FakeCRM: added test contact contact-1 but couldn't delete it, so delete it by hand.")
            ->assertFailed();
    }

    #[Test]
    public function subscribe_is_skipped_for_listeners_that_cant_clean_up()
    {
        $this->makeForm('newsletter');
        Event::forget(FormSubmitted::class);
        $this->useListenersIn('App');
        Event::listen(FormSubmitted::class, 'D3Creative\Canary\Tests\Fixtures\App\Listeners\ForgottenCrm@handle');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com', '--subscribe' => true])
            ->expectsOutputToContain('newsletter: ForgottenCRM check passed. It has no canaryContact and canaryDelete methods, so --subscribe was skipped.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_about_listeners_without_the_canary_methods()
    {
        $this->makeForm('contact', [self::EMAIL]);
        Event::listen(FormSubmitted::class, PlainListener::class);
        Event::listen(SubmissionCreated::class, SendEmailVerificationNotification::class);
        Event::listen(FormSubmitted::class, function () {});

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain(PlainListener::class.' has no Canary methods. See https://github.com/d3creativeuk/statamic-canary#checking-integrations')
            ->expectsOutputToContain(SendEmailVerificationNotification::class." comes from a package, so Canary can't check it")
            ->expectsOutputToContain("A closure listens for form submissions, so Canary can't check it")
            ->doesntExpectOutputToContain(FakeCrm::class)
            ->assertSuccessful();
    }

    /**
     * Points app_path() at tests/Fixtures/App, so its Listeners folder is scanned.
     */
    private function useListenersIn(string $folder): void
    {
        $this->app->useAppPath(dirname(__DIR__).'/Fixtures/'.$folder);
        (new ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'D3Creative\\Canary\\Tests\\Fixtures\\'.$folder.'\\');
    }
}
