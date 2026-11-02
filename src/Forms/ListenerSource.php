<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Support\Arr;
use ReflectionClass;
use Statamic\Facades\Form;
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
        if (! $this->listensForForms()) {
            return [];
        }

        $handles = $this->handles();

        return array_fill_keys($handles, count($handles) === 1 ? $this->fields() : []);
    }

    /**
     * Whether it mentions a form event, so it can be a form listener at all.
     */
    public function listensForForms(): bool
    {
        return (bool) preg_match('/\b(FormSubmitted|SubmissionCreated)\b/', $this->code);
    }

    /**
     * Whether it sets a form's emails, as in $submission->form()->email([...]).
     * The getter, ->email(), takes no argument and is left out.
     */
    public function buildsEmails(): bool
    {
        return (bool) preg_match('/->email\(\s*[^\s)]/', $this->code);
    }

    /**
     * A listener that rejects submissions, such as a reCAPTCHA check, rather
     * than sending them anywhere.
     */
    public function isValidator(): bool
    {
        return str_contains($this->code, 'ValidationException') && ! $this->service();
    }

    /**
     * Whether forms beyond the ones found may reach the listener, through an else
     * branch or a default arm, which can't be read reliably.
     */
    public function mayHandleOthers(): bool
    {
        return $this->handles() && preg_match('/\belse\b|\bdefault\s*(=>|:)/', $this->code);
    }

    public function service(): ?string
    {
        return $this->services()[0] ?? null;
    }

    /**
     * Every service the code names, such as ['Mailchimp', 'Zoho'].
     */
    public function services(): array
    {
        return array_values(array_filter(self::SERVICES, fn ($service) => stripos($this->code, str_replace(' ', '', $service)) !== false
            || stripos($this->code, $service) !== false));
    }

    private function handles(): array
    {
        // A form's handle: the handle() call itself, or a variable assigned from it.
        $vars = $this->handleVariables();
        $call = '[^;{]*?handle\(\)';
        $left = $vars ? "(?:handle\(\)|\\\$(?:{$vars})\b)" : 'handle\(\)';
        $right = $vars ? "(?:\\\$[^;]*?handle\(\)|\\\$(?:{$vars})\b)" : '\\$[^;]*?handle\(\)';
        $any = $vars ? "(?:{$call}|\\\$(?:{$vars})\b)" : $call;
        $string = '[\'"]([\w-]+)[\'"]';

        $handles = [];

        // handle() == 'contact', $handle !== 'contact' and 'contact' === $form->handle()
        preg_match_all("/{$left}\s*[!=]==?\s*{$string}/", $this->code, $matches);
        $handles[] = $matches[1];
        preg_match_all("/{$string}\s*[!=]==?\s*{$right}/", $this->code, $matches);
        $handles[] = $matches[1];

        // handle() !== config('acorn.form'), read from the site's config
        preg_match_all("/{$left}\s*[!=]==?\s*config\(\s*[\'\"]([\w.-]+)[\'\"]/", $this->code, $matches);
        foreach ($matches[1] as $key) {
            $handles[] = array_filter(Arr::wrap(config($key)), 'is_string');
        }

        // in_array($form->handle(), ['contact', 'quote'])
        preg_match_all("/in_array\(\s*{$any}\s*,\s*\[([^\]]*)\]/", $this->code, $matches);
        foreach ($matches[1] as $list) {
            $handles[] = $this->strings($list);
        }

        // $this->groups[$form->handle()], with protected array $groups = ['contact' => ...]
        preg_match_all("/\\\$this->(\w+)\[\s*{$any}/", $this->code, $matches);
        foreach (array_unique($matches[1]) as $property) {
            if (preg_match('/\$'.$property.'\s*=\s*\[(.*?)\];/s', $this->code, $map)) {
                preg_match_all('/[\'"]([\w-]+)[\'"]\s*=>/', $map[1], $keys);
                $handles[] = $keys[1];
            }
        }

        // Str::startsWith($handle, 'b2b_') and str_starts_with(), matched against the site's forms
        preg_match_all("/(?:Str::startsWith|str_starts_with)\(\s*{$any}\s*,\s*{$string}/", $this->code, $matches);
        foreach ($matches[1] as $prefix) {
            $handles[] = Form::all()->map->handle()->filter(fn ($handle) => str_starts_with($handle, $prefix))->values()->all();
        }

        // match ($handle) { 'contact', 'quote' => ... } and switch ($handle) { case 'contact': ... }
        preg_match_all("/\b(match|switch)\s*\(\s*{$any}\s*\)\s*\{/", $this->code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $block = $this->block($match[0][1] + strlen($match[0][0]) - 1);

            if ($match[1][0] === 'switch') {
                preg_match_all("/\bcase\s+{$string}\s*:/", $block, $cases);
                $handles[] = $cases[1];
            } else {
                // The conditions before each =>, not the values after it.
                preg_match_all("/(?:^|,)\s*((?:{$string}\s*,\s*)*{$string})\s*=>/", $block, $arms);
                $handles[] = array_merge(...array_map(fn ($arm) => $this->strings($arm), $arms[1]));
            }
        }

        // if ($handle === 'old') { return; } leaves that form out. After !==, a return
        // means the listener handles only that form, as Acorn's does.
        preg_match_all("/if\s*\(\s*{$left}\s*===?\s*{$string}\s*\)\s*\{?\s*return\b/", $this->code, $matches);
        $skipped = $matches[1];
        preg_match_all("/if\s*\(\s*{$string}\s*===?\s*{$right}\s*\)\s*\{?\s*return\b/", $this->code, $matches);
        $skipped = array_merge($skipped, $matches[1]);

        return array_values(array_diff(array_unique(array_merge(...$handles)), $skipped));
    }

    /**
     * Variables assigned from a handle() call, as in $handle = $form->handle();
     */
    private function handleVariables(): string
    {
        preg_match_all('/\$(\w+)\s*=\s*[^;=]*?->handle\(\)\s*;/', $this->code, $matches);

        return implode('|', array_unique($matches[1]));
    }

    /**
     * The code inside the braces that open at $offset.
     */
    private function block(int $offset): string
    {
        $depth = 0;

        for ($i = $offset; $i < strlen($this->code); $i++) {
            $depth += match ($this->code[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($this->code, $offset + 1, $i - $offset - 1);
            }
        }

        return substr($this->code, $offset + 1);
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
