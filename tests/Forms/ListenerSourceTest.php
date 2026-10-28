<?php

namespace D3Creative\Canary\Tests\Forms;

use D3Creative\Canary\Forms\ListenerSource;
use D3Creative\Canary\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ListenerSourceTest extends TestCase
{
    #[Test]
    #[DataProvider('handlePatterns')]
    public function it_finds_the_forms_a_listener_handles(string $body, array $expected)
    {
        $this->assertSame($expected, array_keys($this->source($body)->forms()));
    }

    public static function handlePatterns(): array
    {
        return [
            'equals' => ["if (\$event->submission->form->handle() == 'contact') {}", ['contact']],
            'strictly not equal' => ['if ($event->submission->form()->handle() !== "quote") { return; }', ['quote']],
            'handle on the right' => ["if ('contact' === \$event->submission->form()->handle()) {}", ['contact']],
            'in_array' => ["if (in_array(\$event->submission->form()->handle(), ['contact', 'quote'])) {}", ['contact', 'quote']],
            'map property' => [
                "\$id = \$this->groups[\$event->submission->form()->handle()] ?? null;\n}\nprotected array \$groups = [\n'alerts' => '123',\n'drip' => '456',\n];\nfunction x() {",
                ['alerts', 'drip'],
            ],
            'no handle' => ['$event->submission->save();', []],
        ];
    }

    #[Test]
    public function it_reads_a_form_handle_from_config()
    {
        config(['acorn.form' => 'acorn_submit']);

        $this->assertSame(['acorn_submit'], array_keys($this->source("if (\$submission->form()->handle() !== config('acorn.form')) { return; }")->forms()));
        $this->assertSame([], $this->source("if (\$submission->form()->handle() !== config('missing.form')) { return; }")->forms());
    }

    #[Test]
    public function it_lists_the_fields_a_single_form_listener_reads()
    {
        $source = $this->source(<<<'PHP'
            if ($event->submission->form()->handle() == 'contact') {
                // $event->submission->commented_out
                $email = $event->submission->email ?? null;
                $name = $event->submission->get('full_name');
                $event->submission->set('status', 'sent')->save();
                $data = $event->submission->data();
                $form = $event->submission->form;
            }
            PHP);

        $this->assertSame(['contact' => ['email', 'full_name']], $source->forms());
    }

    #[Test]
    public function it_doesnt_guess_fields_for_a_listener_with_several_forms()
    {
        $source = $this->source("if (in_array(\$event->submission->form()->handle(), ['contact', 'quote'])) { \$event->submission->email; }");

        $this->assertSame(['contact' => [], 'quote' => []], $source->forms());
    }

    #[Test]
    public function it_ignores_listeners_for_other_events()
    {
        $source = new ListenerSource("<?php\nclass X { public function handle(EntrySaved \$event) { if (\$event->entry->collection()->handle() == 'glossary') {} } }");

        $this->assertSame([], $source->forms());
    }

    #[Test]
    public function it_names_the_service()
    {
        $this->assertSame('ActiveCampaign', $this->source('SyncFormToActiveCampaign::dispatch();')->service());
        $this->assertSame('Campaign Monitor', $this->source('new CampaignMonitorClient;')->service());
        $this->assertNull($this->source('Log::info("hi");')->service());
    }

    private function source(string $body): ListenerSource
    {
        return new ListenerSource("<?php\nuse Statamic\\Events\\FormSubmitted;\nclass Listener {\npublic function handle(FormSubmitted \$event) {\n{$body}\n}\n}");
    }
}
