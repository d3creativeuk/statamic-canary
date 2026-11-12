<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Forms\Form;
use Statamic\Http\Controllers\FormController;
use Statamic\Http\Requests\FrontendFormRequest;
use Statamic\Sites\Site;

/**
 * Sends a submission the way a visitor's does, for --submit: Statamic's own
 * FrontendFormRequest validates it, then FormController::submit() runs the
 * honeypot, FormSubmitted, SubmissionCreated (or the save) and SendEmails, with
 * jobs going on the site's real queue. Only the HTTP middleware is skipped: CSRF,
 * the session and the route's throttle.
 */
class RealSubmission
{
    /** Each job queued during the submission: [connection, queue, job name]. */
    public array $queued = [];

    /** Field => messages, when validation or a listener rejected it. */
    public array $errors = [];

    /** False when the honeypot or a listener returning false dropped it silently. */
    public bool $created = false;

    public static function send(Form $form, array $data, Site $site): self
    {
        $result = new self;

        $request = Request::create(route('statamic.forms.submit', $form->handle()), 'POST', $data, server: [
            // A listener's rate limit then counts Canary on its own, not with the site's visitors.
            'REMOTE_ADDR' => '127.0.0.1',
            // So a failure throws ValidationException and success returns JSON, with no session.
            'HTTP_ACCEPT' => 'application/json',
            // Statamic picks the site, and the language of its messages, from the page the form was on.
            'HTTP_REFERER' => $site->absoluteUrl(),
        ]);

        $route = clone app('router')->getRoutes()->getByName('statamic.forms.submit');
        $route->bind($request);
        $route->setParameter('form', $form);
        $request->setRouteResolver(fn () => $route);

        $formRequest = FrontendFormRequest::createFrom($request);
        $formRequest->setContainer(app())->setRedirector(app('redirect'));

        $previousRequest = app('request');
        $previousUrlRequest = app('url')->getRequest();
        $running = true;

        // Dispatchers can't forget one listener, so this one switches itself off.
        Event::listen(JobQueued::class, function (JobQueued $event) use ($result, &$running) {
            if ($running) {
                // A job on the connection's default queue doesn't name it.
                $queue = $event->queue ?? config("queue.connections.{$event->connectionName}.queue");

                $result->queued[] = [$event->connectionName, $queue, is_object($event->job) ? class_basename($event->job) : 'a closure'];
            }
        });

        // Listeners resolve the request from the container, as they would in a real one.
        app()->instance('request', $formRequest);
        app('url')->setRequest($formRequest);

        try {
            $formRequest->validateResolved();

            $response = app(FormController::class)->submit($formRequest, $form);

            $result->created = (bool) ($response->getOriginalContent()['submission_created'] ?? false);
        } catch (ValidationException $e) {
            $result->errors = $e->errors();
        } finally {
            $running = false;
            app()->instance('request', $previousRequest);
            app('url')->setRequest($previousUrlRequest);
        }

        return $result;
    }
}
