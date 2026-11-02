<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Blocks requests through Laravel's HTTP client that could change something,
 * such as a token refresh or a new contact, while a check runs. SDKs with their
 * own HTTP client, such as MailerLite's, aren't covered.
 */
class ReadOnlyRequests
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /** The requests blocked by the current run(), or null outside one. */
    private static ?array $blocked = null;

    private static ?Factory $guarded = null;

    /**
     * Runs the callback and returns its result. $blocked lists what was stopped,
     * such as "POST accounts.zoho.eu", even if the listener caught the exception.
     */
    public static function run(callable $callback, ?array &$blocked = null): mixed
    {
        self::guard();

        [$outer, self::$blocked] = [self::$blocked, []];

        try {
            return $callback();
        } finally {
            $blocked = self::$blocked;
            self::$blocked = $outer;
        }
    }

    /**
     * Global middleware can't be removed, so it's added once per HTTP factory and
     * does nothing outside run().
     */
    private static function guard(): void
    {
        $factory = Http::getFacadeRoot();

        if (self::$guarded === $factory) {
            return;
        }

        $factory->globalRequestMiddleware(function (RequestInterface $request) {
            if (self::$blocked === null || in_array($request->getMethod(), self::SAFE)) {
                return $request;
            }

            self::$blocked[] = $request->getMethod().' '.$request->getUri()->getHost();

            throw new RuntimeException("Canary blocked a {$request->getMethod()} request to {$request->getUri()->getHost()}, as a check must only read.");
        });

        self::$guarded = $factory;
    }
}
