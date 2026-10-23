<?php

namespace D3Creative\Canary\Tests;

use D3Creative\Canary\ServiceProvider;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Forms, their blueprints and any submissions are written here, then
        // deleted in tearDown, so tests can't leak into each other.
        $app['config']->set('statamic.forms.forms', $this->contentPath('forms'));
        $app['config']->set('statamic.system.blueprints_path', $this->contentPath('blueprints'));
        $app['config']->set('statamic.stache.stores.form-submissions.directory', $this->contentPath('submissions'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->contentPath());

        parent::tearDown();
    }

    protected function makeForm(string $handle, array $emails = [], array $fields = [], bool $store = false)
    {
        $fields = $fields ?: [
            ['handle' => 'full_name', 'field' => ['type' => 'text']],
            ['handle' => 'business_email', 'field' => ['type' => 'text', 'input_type' => 'email']],
            ['handle' => 'message_text', 'field' => ['type' => 'textarea']],
        ];

        Blueprint::make($handle)
            ->setNamespace('forms')
            ->setContents(['tabs' => ['main' => ['sections' => [['fields' => $fields]]]]])
            ->save();

        $form = Form::make($handle)->title(ucfirst($handle))->store($store)->email($emails);
        $form->save();

        return $form;
    }

    private function contentPath(string $path = ''): string
    {
        return rtrim(__DIR__.'/__fixtures__/tmp/'.$path, '/');
    }
}
