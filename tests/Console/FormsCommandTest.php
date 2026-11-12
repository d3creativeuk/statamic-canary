<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Console\Commands\FormsCommand;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Addon;
use Statamic\Facades\Form;
use Statamic\Fields\Field;
use Statamic\Forms\Email;
use Statamic\Forms\SendEmail;

class FormsCommandTest extends TestCase
{
    private const CLIENT_EMAIL = [
        'to' => 'client@example.com',
        'cc' => 'manager@example.com',
        'bcc' => 'archive@example.com',
        'from' => 'site@example.com',
        'subject' => '{{ full_name }} submitted the contact form.',
        'mailer' => 'smtp',
        'html' => 'emails/contact',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Mail::fake();
    }

    #[Test]
    public function it_sends_each_email_to_the_test_address_only()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL, ['to' => 'sales@example.com']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("2 email(s) for contact dispatched to me@example.com, using the form's smtp mailer.")
            ->assertSuccessful();

        Mail::assertSent(Email::class, 2);
        Mail::assertSent(Email::class, fn (Email $mail) => $mail->getConfig()['to'] === 'me@example.com'
            && ! isset($mail->getConfig()['cc'])
            && ! isset($mail->getConfig()['bcc']));
        Mail::assertNotSent(Email::class, fn (Email $mail) => $mail->getConfig()['to'] !== 'me@example.com');
    }

    #[Test]
    public function it_prefixes_the_subject_and_keeps_the_rest_of_the_config()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])->assertSuccessful();

        Mail::assertSent(Email::class, fn (Email $mail) => $mail->getConfig() === [
            'to' => 'me@example.com',
            'from' => 'site@example.com',
            'subject' => 'TEST: {{ full_name }} submitted the contact form.',
            'mailer' => 'smtp',
            'html' => 'emails/contact',
        ]);
    }

    #[Test]
    public function the_built_email_goes_to_the_test_address_with_sample_data_in_the_subject()
    {
        $this->makeForm('contact', [['to' => 'client@example.com', 'subject' => '{{ full_name }} submitted the contact form.']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])->assertSuccessful();

        Mail::assertSent(Email::class, function (Email $mail) {
            $mail->build();

            return $mail->hasTo('me@example.com')
                && ! $mail->hasTo('client@example.com')
                && $mail->subject === 'TEST: Test submission submitted the contact form.';
        });
    }

    #[Test]
    public function it_pushes_the_emails_onto_the_queue()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('Emails go on the default queue of the redis connection, so they only send when a queue worker runs.')
            ->doesntExpectOutputToContain('queue connection is sync')
            ->assertSuccessful();

        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->config['to'] === 'me@example.com');
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_doesnt_fire_submission_events_or_save_a_submission()
    {
        Event::fake([FormSubmitted::class, SubmissionCreated::class]);
        $this->makeForm('contact', [self::CLIENT_EMAIL], store: true);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])->assertSuccessful();

        Event::assertNotDispatched(FormSubmitted::class);
        Event::assertNotDispatched(SubmissionCreated::class);
        $this->assertCount(0, Form::find('contact')->submissions());
    }

    #[Test]
    public function it_doesnt_save_a_submission_for_a_form_with_file_uploads()
    {
        // SendEmails chains DeleteTemporaryAttachments for forms with a files
        // field, and that job saves the submission when the form stores them.
        $this->makeForm('application', [self::CLIENT_EMAIL], [
            ['handle' => 'full_name', 'field' => ['type' => 'text']],
            ['handle' => 'cv', 'field' => ['type' => 'files']],
        ], store: true);

        $this->artisan('canary:forms', ['forms' => ['application'], '--to' => 'me@example.com'])->assertSuccessful();

        Mail::assertSent(Email::class, 1);
        $this->assertCount(0, Form::find('application')->submissions());
        $this->assertTrue(Form::find('application')->store());
    }

    #[Test]
    public function it_leaves_the_saved_form_unchanged()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])->assertSuccessful();

        // find() reads the form's YAML from disk, so this is what was saved.
        $this->assertSame([self::CLIENT_EMAIL], Form::find('contact')->email());
    }

    #[Test]
    public function it_handles_a_single_email_config()
    {
        $this->makeForm('contact', self::CLIENT_EMAIL);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('1 email(s) for contact dispatched')
            ->assertSuccessful();

        Mail::assertSent(Email::class, fn (Email $mail) => $mail->getConfig()['to'] === 'me@example.com');
    }

    #[Test]
    public function a_form_with_nothing_to_test_warns_but_doesnt_fail_the_others()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['contact', 'newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('newsletter has no emails or integrations, so there is nothing to test.')
            ->expectsOutputToContain('Nothing to test in newsletter.')
            ->expectsOutputToContain('Tested 1 form: 1 email dispatched.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_lists_and_checks_the_cc_and_bcc_it_removes()
    {
        $this->makeForm('contact', [[...self::CLIENT_EMAIL, 'cc' => 'manager@outer.test', 'bcc' => 'archive@outer.test']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--dry-run' => true, '-v' => true])
            ->expectsOutputToContain('contact: checked, not emailed: client@example.com, cc manager@outer.test, bcc archive@outer.test')
            ->expectsOutputToContain('would go to me@example.com instead of client@example.com, cc manager@outer.test, bcc archive@outer.test.')
            ->assertSuccessful();
    }

    #[Test]
    public function an_invalid_cc_fails_as_it_would_stop_the_real_email()
    {
        $this->makeForm('contact', [[...self::CLIENT_EMAIL, 'cc' => 'manager']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('would cc "manager", which isn\'t an email address.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_broken_template_fails_before_anything_is_sent()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL, [...self::CLIENT_EMAIL, 'html' => 'emails/missing', 'subject' => 'Thanks']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("contact: the emails/missing template failed to render, so none of the form's emails were sent.")
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function emails_that_differ_by_more_than_recipient_are_each_sent()
    {
        $this->makeForm('contact', [
            [...self::CLIENT_EMAIL, 'to' => 'sales@outer.test'],
            [...self::CLIENT_EMAIL, 'to' => 'events@outer.test'],
            [...self::CLIENT_EMAIL, 'to' => 'press@outer.test', 'reply_to' => 'press@outer.test'],
        ]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("2 email(s) for contact dispatched to me@example.com, using the form's smtp mailer, covering 3 that only differ by recipient.")
            ->assertSuccessful();

        Mail::assertSent(Email::class, 2);
    }

    #[Test]
    public function a_run_that_tests_nothing_fails()
    {
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("Canary tested nothing, so it can't say any form works.")
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function an_unknown_form_fails_but_the_others_still_run()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['nope', 'contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('No form with the handle nope.')
            ->expectsOutputToContain('1 email(s) for contact dispatched')
            ->assertFailed();

        Mail::assertSent(Email::class, 1);
    }

    #[Test]
    public function an_error_while_sending_fails_the_run()
    {
        config(['statamic.forms.send_email_job' => 'NotAJob']);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('contact: ')
            ->assertFailed();
    }

    #[Test]
    public function an_invalid_address_fails()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'not-an-address'])
            ->expectsOutputToContain('not-an-address is not an email address.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_warns_when_the_queue_is_sync()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('The queue connection is sync')
            ->doesntExpectOutputToContain('Emails go on')
            ->assertSuccessful();
    }

    #[Test]
    public function it_names_the_driver_when_the_connection_is_named_differently()
    {
        config([
            'queue.default' => 'emails',
            'queue.connections.emails' => ['driver' => 'database', 'queue' => 'mail'],
        ]);
        Queue::fake();
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('Emails go on the mail queue of the emails connection (database)')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_when_the_mailer_never_delivers()
    {
        config(['mail.default' => 'log']);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("Emails send with the log mailer, so they're never delivered. Set MAIL_MAILER to test delivery.")
            ->assertSuccessful();
    }

    #[Test]
    public function it_names_the_mailer()
    {
        config(['mail.default' => 'postmark-live', 'mail.mailers.postmark-live' => ['transport' => 'postmark']]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('Emails send with the postmark-live mailer (postmark).')
            ->doesntExpectOutputToContain('never delivered')
            ->assertSuccessful();
    }

    #[Test]
    public function a_mailer_that_cant_be_created_fails_before_anything_is_sent()
    {
        // Postmark's transport package isn't installed in the tests, as on a site that dropped it.
        config(['mail.mailers.postmark' => ['transport' => 'postmark']]);
        $this->makeForm('contact', [[...self::CLIENT_EMAIL, 'mailer' => 'postmark']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--dry-run' => true])
            ->expectsOutputToContain("contact: the postmark mailer can't be created, so its emails couldn't be sent.")
            ->assertFailed();

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_mailer_whose_transport_a_package_registers_can_be_created()
    {
        // Registered the way mailersend/laravel-driver does, on the site's own manager.
        Mail::extend('package-transport', fn () => new ArrayTransport);
        config(['mail.mailers.package' => ['transport' => 'package-transport']]);
        $this->makeForm('contact', [[...self::CLIENT_EMAIL, 'mailer' => 'package']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--dry-run' => true])
            ->doesntExpectOutputToContain("can't be created")
            ->assertSuccessful();

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->doesntExpectOutputToContain("can't be created")
            ->assertSuccessful();
    }

    #[Test]
    public function checking_a_mailer_leaves_the_sites_mailers_alone()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $manager = app('mail.manager');
        $manager = $manager instanceof MailFake ? $manager->manager : $manager;
        $mailer = $manager->mailer('smtp');

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->assertSuccessful();

        $this->assertSame($mailer, $manager->mailer('smtp'));
    }

    #[Test]
    public function an_email_without_a_mailer_checks_the_default_one()
    {
        config(['mail.default' => 'typo']);
        $this->makeForm('contact', [Arr::except(self::CLIENT_EMAIL, 'mailer')]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("contact: the typo mailer can't be created")
            ->assertFailed();
    }

    #[Test]
    public function it_says_where_the_smtp_mailer_connects()
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('Emails send with the smtp mailer (127.0.0.1:2525).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_names_a_mailer_set_on_the_form()
    {
        $this->makeForm('contact', [[...self::CLIENT_EMAIL, 'mailer' => 'array']]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
            ->expectsOutputToContain("1 email(s) for contact dispatched to me@example.com, using the form's array mailer.")
            ->assertSuccessful();
    }

    #[Test]
    public function it_falls_back_to_the_config()
    {
        config(['canary.to' => 'config@example.com', 'canary.forms' => ['contact']]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms')
            ->expectsOutputToContain("1 email(s) for contact dispatched to config@example.com, using the form's smtp mailer.")
            ->doesntExpectOutputToContain('quote')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_without_a_test_address()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact']])
            ->expectsOutputToContain('No test address. Pass --to or set CANARY_TO')
            ->assertFailed();
    }

    #[Test]
    public function it_lets_you_pick_forms_when_none_are_given()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('newsletter');
        $this->makeForm('quote', [self::CLIENT_EMAIL, [...self::CLIENT_EMAIL, 'to' => '{{ business_email }}', 'subject' => 'Thanks for asking for a quote']]);
        $this->makeForm('reply', [[...self::CLIENT_EMAIL, 'to' => '{{ business_email }}']]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice(
                'Which forms should Canary test? 4 forms found.',
                ['quote'],
                [
                    'contact', 'quote', 'reply', 'newsletter',
                    'contact - notification email',
                    'quote - notification and auto-reply emails',
                    'reply - auto-reply email',
                    'newsletter - no emails or integrations found',
                ],
            )
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->expectsOutputToContain('2 email(s) for quote dispatched')
            ->doesntExpectOutputToContain('for contact')
            ->assertSuccessful();

        Mail::assertSent(Email::class, 2);
        $this->assertFileDoesNotExist($this->settingsPath());
    }

    #[Test]
    public function picked_forms_can_be_saved_and_are_used_next_time()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['quote'], ['contact', 'quote', 'contact - notification email', 'quote - notification email'])
            ->expectsConfirmation('Save these as the forms to test next time?', 'yes')
            ->expectsOutputToContain('Saved. Run with --pick to choose again.')
            ->assertSuccessful();

        $this->assertSame(['quote'], Addon::get('d3creative/statamic-canary')->settings()->get('forms'));

        $this->artisan('canary:forms', ['--to' => 'me@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Testing the saved forms.')
            ->expectsOutputToContain('1 email(s) for quote dispatched')
            ->doesntExpectOutputToContain('for contact')
            ->assertSuccessful();
    }

    #[Test]
    public function pick_shows_the_list_even_when_forms_are_saved()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);
        $this->saveForms(['quote']);

        $this->artisan('canary:forms', ['--to' => 'me@example.com', '--pick' => true])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['contact'], ['contact', 'quote', 'contact - notification email', 'quote - notification email'])
            ->expectsConfirmation('Save these as the forms to test next time?', 'yes')
            ->expectsOutputToContain('1 email(s) for contact dispatched')
            ->assertSuccessful();

        $this->assertSame(['contact'], Addon::get('d3creative/statamic-canary')->settings()->get('forms'));
    }

    #[Test]
    public function configured_forms_win_over_saved_forms()
    {
        config(['canary.forms' => ['contact']]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);
        $this->saveForms(['quote']);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsOutputToContain('1 email(s) for contact dispatched')
            ->doesntExpectOutputToContain('for quote')
            ->assertSuccessful();
    }

    #[Test]
    public function it_doesnt_offer_to_save_when_forms_are_configured()
    {
        config(['canary.forms' => ['contact']]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        // An unexpected confirmation would fail the test.
        $this->artisan('canary:forms', ['--to' => 'me@example.com', '--pick' => true])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['quote'], ['contact', 'quote', 'contact - notification email', 'quote - notification email'])
            ->expectsOutputToContain('1 email(s) for quote dispatched')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->settingsPath());
    }

    #[Test]
    public function it_fails_without_forms_when_not_interactive()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('No forms to test.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_fails_when_the_site_has_no_forms()
    {
        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsOutputToContain('No forms found.')
            ->assertFailed();
    }

    #[Test]
    public function an_unknown_site_fails()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--site' => 'nope'])
            ->expectsOutputToContain('No site with the handle nope.')
            ->assertFailed();

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_sends_as_the_given_site()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--site' => 'default'])
            ->assertSuccessful();

        Mail::assertSent(Email::class, fn (Email $mail) => $mail->getSite()->handle() === 'default');
    }

    #[Test]
    #[DataProvider('sampleValues')]
    public function it_picks_a_sample_value_for_each_field_type(array $config, mixed $expected)
    {
        $field = new Field('sample', $config);

        $this->assertSame($expected, $this->callPrivate('sampleValue', $field, 'me@example.com'));
    }

    public static function sampleValues(): array
    {
        return [
            'email input' => [['type' => 'text', 'input_type' => 'email'], 'me@example.com'],
            'url input' => [['type' => 'text', 'input_type' => 'url'], 'https://example.com'],
            'tel input' => [['type' => 'text', 'input_type' => 'tel'], '000'],
            'assets' => [['type' => 'assets'], null],
            'files' => [['type' => 'files'], null],
            'integer' => [['type' => 'integer'], 1],
            'toggle' => [['type' => 'toggle'], true],
            'checkboxes' => [['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']], ['a', 'b']],
            'multi-select' => [['type' => 'select', 'multiple' => true, 'options' => [['key' => 'a', 'value' => 'A'], ['key' => 'b', 'value' => 'B']]], ['a', 'b']],
            'select' => [['type' => 'select', 'options' => ['a', 'b']], 'a'],
            'radio' => [['type' => 'radio', 'options' => [['key' => 'a', 'value' => 'A']]], 'a'],
            'textarea' => [['type' => 'textarea'], 'Test submission from D3 Creative. Please ignore.'],
            'text' => [['type' => 'text'], 'Test submission'],
            'text with an email rule' => [['type' => 'text', 'validate' => ['required', 'email:filter']], 'me@example.com'],
        ];
    }

    #[Test]
    public function samples_never_mention_canary()
    {
        foreach ([true, false] as $clientFacing) {
            $this->assertSame('Test submission from D3 Creative. Please ignore.', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'textarea']), 'me@example.com', $clientFacing));
            $this->assertSame('Test submission', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'text']), 'me@example.com', $clientFacing));
        }
    }

    #[Test]
    public function the_test_message_names_who_it_is_from()
    {
        config(['canary.from_name' => 'Acme Web']);
        $this->assertSame('Test submission from Acme Web. Please ignore.', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'textarea']), 'me@example.com'));

        config(['canary.from_name' => '']);
        $this->assertSame('Test submission. Please ignore.', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'textarea']), 'me@example.com'));
    }

    #[Test]
    public function client_facing_multi_value_samples_have_one_option()
    {
        $this->assertSame(['a'], $this->callPrivate('sampleValue', new Field('sample', ['type' => 'checkboxes', 'options' => ['a', 'b']]), 'me@example.com', true));
        $this->assertSame(['a'], $this->callPrivate('sampleValue', new Field('sample', ['type' => 'select', 'multiple' => true, 'options' => ['a', 'b']]), 'me@example.com', true));
    }

    #[Test]
    public function the_date_sample_is_today()
    {
        $this->assertSame(now()->toDateString(), $this->callPrivate('sampleValue', new Field('sample', ['type' => 'date']), 'me@example.com'));
    }

    #[Test]
    #[DataProvider('optionFormats')]
    public function it_finds_the_first_option_in_each_format(array $options)
    {
        $this->assertSame('a', $this->callPrivate('firstOption', $options));
    }

    public static function optionFormats(): array
    {
        return [
            'list' => [['a', 'b']],
            'key: label pairs' => [['a' => 'A', 'b' => 'B']],
            'key and value items' => [[['key' => 'a', 'value' => 'A'], ['key' => 'b', 'value' => 'B']]],
        ];
    }

    private function saveForms(array $handles): void
    {
        Addon::get('d3creative/statamic-canary')->settings()->set('forms', $handles)->save();
    }

    private function callPrivate(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(FormsCommand::class, $method))->invoke(new FormsCommand, ...$arguments);
    }
}
