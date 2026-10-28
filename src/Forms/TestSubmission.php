<?php

namespace D3Creative\Canary\Forms;

use Statamic\Forms\Submission;

/**
 * A submission that can't be saved. Integration listeners often save the
 * submission they're given, which would store it and fire SubmissionCreated.
 */
class TestSubmission extends Submission
{
    public function save()
    {
        return true;
    }
}
