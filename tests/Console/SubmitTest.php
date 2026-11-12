<?php

namespace D3Creative\Canary\Tests\Console;

use D3Creative\Canary\Forms\DeleteTestContact;
use D3Creative\Canary\Tests\Fixtures\AddToFakeCrm;
use D3Creative\Canary\Tests\Fixtures\FakeCrm;
use D3Creative\Canary\Tests\Fixtures\HandleFormSubmissions;
use D3Creative\Canary\Tests\Fixtures\SampledCrm;
use D3Creative\Canary\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Forms\Email;
use Statamic\Forms\SendEmail;

/**
 * --submit sends a real submission: Statamic's validation, the form's listeners and
 * the real queue all run, from a new test address for each form.
 */
class SubmitTest extends TestCase
{
    private const EMAIL = ['to' => 'client@example.com', 'subject' => 'New contact'];

    private const ADDRESS = 'me+test-contact-20261112140500@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-12 14:05:00');
        Sleep::fake();
        FakeCrm::reset();
        HandleFormSubmissions::reset();
        config(['canary.submit' => ['contact']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function submit(array $options = [])
    {
        return $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--submit' => true, ...$options]);
    }

    #[Test]
    public function it_sends_a_real_submission_through_the_listeners_and_queue()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Event::listen(FormSubmitted::class, FakeCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->submit()
            ->expectsOutputToContain('contact: submitted from '.self::ADDRESS.', with 1 email(s) to me@example.com.')
            ->expectsOutputToContain("contact: FakeCRM: the test contact arrived (contact-1), added straight away by the listener. It's deleted in 15 minutes")
            ->expectsOutputToContain('Tested 1 form: 1 submission sent, 1 integration check passed.')
            ->assertSuccessful();

        // The listener got the real submission, labelled as a test.
        $this->assertSame(self::ADDRESS, FakeCrm::$received[0]['business_email']);
        $this->assertSame('Test submission from D3 Creative. Please ignore.', FakeCrm::$received[0]['message_text']);

        // The form's email goes through the queue to the test address only.
        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => (fn () => $this->config)->call($job)['to'] === 'me@example.com'
            && (fn () => $this->config)->call($job)['subject'] === 'TEST: New contact');

        Queue::assertPushed(DeleteTestContact::class, fn (DeleteTestContact $job) => $job->listener === FakeCrm::class
            && $job->form === 'contact'
            && $job->id === 'contact-1'
            && $job->delay->equalTo(now()->addMinutes(15)));
    }

    #[Test]
    public function it_waits_for_the_queue_worker_to_add_the_contact()
    {
        // A real queue driver, as Queue::fake() never fires JobQueued.
        $this->useDatabaseQueue();
        Event::listen(FormSubmitted::class, FakeCrm::class);
        FakeCrm::$viaJob = true;
        $this->makeForm('contact');

        // A worker runs the job while Canary waits.
        Sleep::whenFakingSleep(fn () => (new AddToFakeCrm(self::ADDRESS))->handle());

        $this->submit()
            ->expectsOutputToContain('Queued AddToFakeCrm on the default queue of the database connection.')
            ->expectsOutputToContain('the test contact arrived (contact-1), so a queue worker ran its job.')
            ->assertSuccessful();

        Sleep::assertSleptTimes(1);
        $this->assertTrue(DB::table('jobs')->get()->contains(fn ($job) => str_contains($job->payload, 'DeleteTestContact')));
    }

    private function useDatabaseQueue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        ]);

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    #[Test]
    public function it_fails_when_the_contact_never_arrives()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Event::listen(FormSubmitted::class, FakeCrm::class);
        FakeCrm::$viaJob = true;
        $this->makeForm('contact');

        $this->submit()
            ->expectsOutputToContain(self::ADDRESS." hadn't arrived after 90 seconds. Check a queue worker is running")
            ->assertFailed();

        Sleep::assertSleptTimes(18);
        Queue::assertNotPushed(DeleteTestContact::class);
    }

    #[Test]
    public function on_the_sync_queue_the_contact_is_left_to_delete_by_hand()
    {
        config(['queue.default' => 'sync']);
        Mail::fake();
        Event::listen(FormSubmitted::class, FakeCrm::class);
        $this->makeForm('contact', [self::EMAIL]);

        $this->submit()
            ->expectsOutputToContain("The queue is sync, so Canary can't delete it later: delete ".self::ADDRESS.' by hand')
            ->assertSuccessful();

        $this->assertSame([], FakeCrm::$deleted);
        Mail::assertSent(Email::class, function (Email $mail) {
            $mail->build();

            return $mail->hasTo('me@example.com') && ! $mail->hasTo('client@example.com');
        });
    }

    #[Test]
    public function a_form_must_be_listed_before_it_is_submitted()
    {
        config(['canary.submit' => []]);
        Event::listen(FormSubmitted::class, FakeCrm::class);
        $this->makeForm('contact');

        $this->submit()
            ->expectsOutputToContain('it only runs on forms listed in canary.submit (CANARY_SUBMIT). Not listed: contact.')
            ->assertFailed();

        $this->assertSame([], FakeCrm::$received);
    }

    #[Test]
    public function a_validation_error_fails_the_form_and_names_the_field()
    {
        config(['queue.default' => 'sync']);
        Event::listen(FormSubmitted::class, FakeCrm::class);
        $this->makeForm('contact', fields: [
            ['handle' => 'business_email', 'field' => ['type' => 'text', 'input_type' => 'email']],
            ['handle' => 'employees', 'field' => ['type' => 'text', 'validate' => ['required', 'integer']]],
        ]);

        $this->submit()
            ->expectsOutputToContain('contact: the submission was rejected. employees:')
            ->assertFailed();

        $this->assertSame([], FakeCrm::$received);
    }

    #[Test]
    public function a_listener_can_supply_a_value_the_sample_cant()
    {
        config(['queue.default' => 'sync']);
        Event::listen(FormSubmitted::class, SampledCrm::class);
        $this->makeForm('contact', fields: [
            ['handle' => 'business_email', 'field' => ['type' => 'text', 'input_type' => 'email']],
            ['handle' => 'product_id', 'field' => ['type' => 'text', 'validate' => ['required', 'in:product-1']]],
        ]);

        $this->submit()->expectsOutputToContain('contact: submitted from')->assertSuccessful();

        $this->assertSame('product-1', FakeCrm::$received[0]['product_id']);
    }

    #[Test]
    public function the_honeypot_is_left_empty()
    {
        config(['queue.default' => 'sync']);
        Event::listen(FormSubmitted::class, FakeCrm::class);
        $this->makeForm('contact', fields: [
            ['handle' => 'business_email', 'field' => ['type' => 'text', 'input_type' => 'email']],
            ['handle' => 'winnie', 'field' => ['type' => 'text']],
        ])->honeypot('winnie')->save();

        $this->submit()->expectsOutputToContain('contact: submitted from')->assertSuccessful();

        $this->assertCount(1, FakeCrm::$received);
    }

    #[Test]
    public function it_wont_submit_an_address_that_is_already_a_contact()
    {
        Event::listen(FormSubmitted::class, FakeCrm::class);
        FakeCrm::$contacts[self::ADDRESS] = 'real-contact';
        $this->makeForm('contact');

        $this->submit()
            ->expectsOutputToContain(self::ADDRESS.' is already a contact in FakeCRM')
            ->assertFailed();

        $this->assertSame([], FakeCrm::$received);
    }

    #[Test]
    public function emails_built_in_code_need_real_recipients()
    {
        config(['canary.submit' => ['enquiry'], 'queue.default' => 'sync']);
        Mail::fake();
        Event::listen(SubmissionCreated::class, HandleFormSubmissions::class);
        $this->makeForm('enquiry', fields: [
            ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email']],
            ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => ['events' => 'Events', 'sales' => 'Sales']]],
        ]);

        $this->artisan('canary:forms', ['forms' => ['enquiry'], '--to' => 'me@example.com', '--submit' => true])
            ->expectsOutputToContain('builds emails in code, which would go to their real recipients. Add --real-recipients if they should.')
            ->assertFailed();

        $this->assertFalse(HandleFormSubmissions::$handled);
    }

    #[Test]
    public function real_recipients_keeps_the_recipients_and_marks_the_subject()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->makeForm('contact', [self::EMAIL + ['cc' => 'boss@example.com']]);

        $this->submit(['--real-recipients' => true])
            ->expectsOutputToContain('with 1 email(s) to their real recipients.')
            ->assertSuccessful();

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) {
            $config = (fn () => $this->config)->call($job);

            return $config['to'] === 'client@example.com' && $config['cc'] === 'boss@example.com' && $config['subject'] === 'TEST: New contact';
        });
    }

    #[Test]
    public function the_form_keeps_its_real_recipients_afterwards()
    {
        config(['queue.default' => 'sync']);
        Mail::fake();
        $form = $this->makeForm('contact', [self::EMAIL]);

        $this->submit()->assertSuccessful();

        $this->assertSame([self::EMAIL], $form->email());
        $this->assertSame([self::EMAIL], \Statamic\Facades\Form::find('contact')->email());
    }

    #[Test]
    public function the_options_that_cant_be_combined_with_submit_are_refused()
    {
        $this->makeForm('contact');

        $this->artisan('canary:forms', ['forms' => ['contact'], '--to' => 'me@example.com', '--real-recipients' => true])
            ->expectsOutputToContain('--real-recipients only works with --submit.')
            ->assertFailed();

        $this->submit(['--subscribe' => true])->expectsOutputToContain('use one or the other')->assertFailed();
        $this->submit(['--now' => true])->expectsOutputToContain("--submit tests the queue, so it can't be used with --now.")->assertFailed();
        $this->submit(['--dry-run' => true])->expectsOutputToContain("--dry-run sends nothing, so it can't be used with --submit.")->assertFailed();
    }

    #[Test]
    public function the_delete_job_deletes_the_contact()
    {
        FakeCrm::$contacts[self::ADDRESS] = 'contact-1';

        (new DeleteTestContact(FakeCrm::class, 'contact', 'contact-1'))->handle();

        $this->assertSame(['contact-1'], FakeCrm::$deleted);
        $this->assertSame([], FakeCrm::$contacts);
    }
}
