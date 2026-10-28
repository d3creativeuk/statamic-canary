<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Console\Commands\FormsCommand;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
            ->expectsOutputToContain('2 email(s) for contact dispatched to me@example.com.')
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
                && $mail->subject === 'TEST: Test submitted the contact form.';
        });
    }

    #[Test]
    public function it_pushes_the_emails_onto_the_queue()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->makeForm('contact', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com'])
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
    public function a_form_with_nothing_to_test_warns_but_doesnt_fail()
    {
        $this->makeForm('newsletter');

        $this->artisan('canary:forms', ['forms' => ['newsletter'], '--to' => 'me@example.com'])
            ->expectsOutputToContain('newsletter has no emails or integrations, so there is nothing to test.')
            ->assertSuccessful();

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
            ->assertSuccessful();
    }

    #[Test]
    public function it_falls_back_to_the_config()
    {
        config(['canary.to' => 'config@example.com', 'canary.forms' => ['contact']]);
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms')
            ->expectsOutputToContain('1 email(s) for contact dispatched to config@example.com.')
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
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice(
                'Which forms should Canary test? 3 forms found.',
                ['quote'],
                ['contact', 'quote', 'newsletter', 'contact - emails', 'quote - emails', 'newsletter - no emails or integrations found'],
            )
            ->expectsConfirmation('Save these as the forms to test next time?', 'no')
            ->expectsOutputToContain('1 email(s) for quote dispatched')
            ->doesntExpectOutputToContain('for contact')
            ->assertSuccessful();

        Mail::assertSent(Email::class, 1);
        $this->assertFileDoesNotExist($this->settingsPath());
    }

    #[Test]
    public function picked_forms_can_be_saved_and_are_used_next_time()
    {
        $this->makeForm('contact', [self::CLIENT_EMAIL]);
        $this->makeForm('quote', [self::CLIENT_EMAIL]);

        $this->artisan('canary:forms', ['--to' => 'me@example.com'])
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['quote'], ['contact', 'quote', 'contact - emails', 'quote - emails'])
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
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['contact'], ['contact', 'quote', 'contact - emails', 'quote - emails'])
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
            ->expectsChoice('Which forms should Canary test? 2 forms found.', ['quote'], ['contact', 'quote', 'contact - emails', 'quote - emails'])
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
            'checkboxes' => [['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']], ['a']],
            'select' => [['type' => 'select', 'options' => ['a', 'b']], 'a'],
            'radio' => [['type' => 'radio', 'options' => [['key' => 'a', 'value' => 'A']]], 'a'],
            'textarea' => [['type' => 'textarea'], 'Sent by Canary. Please ignore.'],
            'text' => [['type' => 'text'], 'Test'],
        ];
    }

    #[Test]
    public function client_facing_samples_dont_mention_canary()
    {
        $this->assertSame('TEST form submission. Please ignore.', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'textarea']), 'me@example.com', true));
        $this->assertSame('TEST form submission', $this->callPrivate('sampleValue', new Field('sample', ['type' => 'text']), 'me@example.com', true));
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
