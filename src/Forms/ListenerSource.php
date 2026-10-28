<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Support\Arr;
use ReflectionClass;
use Statamic\Forms\Submission;

/**
 * Reads a listener's code to find the forms it handles, the fields it reads
 * and the service it sends to, for listeners without the Canary methods.
 * It's a best guess from common patterns, so nothing here runs the listener.
 */
class ListenerSource
{
    private const SERVICES = [
        'ActiveCampaign', 'Airtable', 'Brevo', 'Campaign Monitor', 'Constant Contact', 'ConvertKit',
        'HubSpot', 'Klaviyo', 'Mailchimp', 'MailerLite', 'Pipedrive', 'Salesforce', 'Sendinblue',
        'Slack', 'Zapier', 'Zoho',
    ];

    private readonly string $code;

    public function __construct(string $code)
    {
        $this->code = self::withoutComments($code);
    }

    public static function fromClass(string $class): self
    {
        $file = (new ReflectionClass($class))->getFileName();

        return new self($file ? (string) file_get_contents($file) : '');
    }

    /**
     * Form handle => fields it reads. Fields are only listed when the listener
     * handles one form, since they can't be matched to forms otherwise.
     */
    public function forms(): array
    {
        if (! preg_match('/\b(FormSubmitted|SubmissionCreated)\b/', $this->code)) {
            return [];
        }

        $handles = $this->handles();

        return array_fill_keys($handles, count($handles) === 1 ? $this->fields() : []);
    }

    public function service(): ?string
    {
        foreach (self::SERVICES as $service) {
            if (stripos($this->code, str_replace(' ', '', $service)) !== false || stripos($this->code, $service) !== false) {
                return $service;
            }
        }

        return null;
    }

    private function handles(): array
    {
        $handles = [];

        // handle() == 'contact', handle() !== 'contact' and 'contact' === $form->handle()
        preg_match_all('/handle\(\)\s*[!=]==?\s*[\'"]([\w-]+)[\'"]/', $this->code, $matches);
        $handles[] = $matches[1];
        preg_match_all('/[\'"]([\w-]+)[\'"]\s*[!=]==?\s*\$[^;]*?handle\(\)/', $this->code, $matches);
        $handles[] = $matches[1];

        // handle() !== config('acorn.form'), read from the site's config
        preg_match_all('/handle\(\)\s*[!=]==?\s*config\(\s*[\'"]([\w.-]+)[\'"]/', $this->code, $matches);
        foreach ($matches[1] as $key) {
            $handles[] = array_filter(Arr::wrap(config($key)), 'is_string');
        }

        // in_array($form->handle(), ['contact', 'quote'])
        preg_match_all('/in_array\([^,]*handle\(\)\s*,\s*\[([^\]]*)\]/', $this->code, $matches);
        foreach ($matches[1] as $list) {
            $handles[] = $this->strings($list);
        }

        // $this->groups[$form->handle()], with protected array $groups = ['contact' => ...]
        preg_match_all('/\$this->(\w+)\[[^\]]*handle\(\)/', $this->code, $matches);
        foreach (array_unique($matches[1]) as $property) {
            if (preg_match('/\$'.$property.'\s*=\s*\[(.*?)\];/s', $this->code, $map)) {
                preg_match_all('/[\'"]([\w-]+)[\'"]\s*=>/', $map[1], $keys);
                $handles[] = $keys[1];
            }
        }

        return array_values(array_unique(array_merge(...$handles)));
    }

    private function fields(): array
    {
        // $submission->email and $submission->get('email'), but not methods such as ->form() or ->data
        preg_match_all('/submission->(\w+)\b(?!\s*\()/', $this->code, $properties);
        preg_match_all('/submission->get\(\s*[\'"]([\w-]+)[\'"]/', $this->code, $gets);

        $methods = array_map('strtolower', get_class_methods(Submission::class));

        return collect($properties[1])
            ->reject(fn ($name) => in_array(strtolower($name), $methods))
            ->merge($gets[1])
            ->unique()
            ->values()
            ->all();
    }

    private function strings(string $code): array
    {
        preg_match_all('/[\'"]([\w-]+)[\'"]/', $code, $matches);

        return $matches[1];
    }

    private static function withoutComments(string $code): string
    {
        return collect(token_get_all($code))
            ->map(fn ($token) => match (true) {
                ! is_array($token) => $token,
                in_array($token[0], [T_COMMENT, T_DOC_COMMENT]) => '',
                default => $token[1],
            })
            ->implode('');
    }
}
