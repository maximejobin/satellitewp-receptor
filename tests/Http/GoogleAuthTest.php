<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Http\GoogleAuth;

/**
 * The live round-trip against Google cannot run here (no client, no public
 * callback URL), so these cover the halves that are ours: the URL we send the
 * browser to, and how we read Google's answers back — including every way a
 * sign-in must fail closed.
 */
final class GoogleAuthTest extends TestCase
{
    /** @var list<Request> */
    private array $sent = [];

    /** @param list<Response> $responses */
    private function auth(array $responses, bool $configured = true): GoogleAuth
    {
        $this->sent = [];
        $stack      = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        return GoogleAuth::fromConfig([
            'client_id'     => $configured ? 'cid.apps.googleusercontent.com' : null,
            'client_secret' => $configured ? 'secret' : null,
        ], new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testNotConfiguredUntilBothHalvesAreSet(): void
    {
        $this->assertFalse($this->auth([], configured: false)->isConfigured());
        $this->assertTrue($this->auth([])->isConfigured());
        $this->assertFalse(GoogleAuth::fromConfig(['client_id' => 'x'])->isConfigured());
    }

    public function testAuthorizationUrlCarriesStateAndRedirect(): void
    {
        $url = $this->auth([])->authorizationUrl('st4te', 'https://x.test/auth/callback');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('cid.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame('https://x.test/auth/callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('st4te', $q['state']);
        $this->assertStringContainsString('email', $q['scope']);
        // Never silently reuse whichever account the browser is signed into.
        $this->assertSame('select_account', $q['prompt']);
    }

    public function testSuccessfulExchangeReturnsTheVerifiedEmail(): void
    {
        $auth = $this->auth([
            $this->json(['access_token' => 'at']),
            $this->json(['email' => 'Boss@Example.com', 'email_verified' => true]),
        ]);

        $this->assertSame('boss@example.com', $auth->emailFromCode('code', 'https://x.test/auth/callback'));

        $this->assertSame('POST', $this->sent[0]->getMethod());
        $this->assertStringContainsString('oauth2.googleapis.com/token', (string) $this->sent[0]->getUri());
        $this->assertSame('Bearer at', $this->sent[1]->getHeaderLine('Authorization'));
    }

    /** An unverified address proves nothing about who is signing in. */
    public function testUnverifiedEmailIsRejected(): void
    {
        $auth = $this->auth([
            $this->json(['access_token' => 'at']),
            $this->json(['email' => 'boss@example.com', 'email_verified' => false]),
        ]);

        $this->assertNull($auth->emailFromCode('code', 'https://x.test/auth/callback'));
    }

    public function testMissingEmailVerifiedFlagIsRejected(): void
    {
        $auth = $this->auth([
            $this->json(['access_token' => 'at']),
            $this->json(['email' => 'boss@example.com']),
        ]);

        $this->assertNull($auth->emailFromCode('code', 'https://x.test/auth/callback'));
    }

    public function testTokenEndpointFailureFailsClosed(): void
    {
        $auth = $this->auth([$this->json(['error' => 'invalid_grant'], 400)]);

        $this->assertNull($auth->emailFromCode('code', 'https://x.test/auth/callback'));
    }

    public function testUserinfoFailureFailsClosed(): void
    {
        $auth = $this->auth([
            $this->json(['access_token' => 'at']),
            $this->json(['error' => 'nope'], 401),
        ]);

        $this->assertNull($auth->emailFromCode('code', 'https://x.test/auth/callback'));
    }

    public function testEmptyCodeNeverReachesGoogle(): void
    {
        $auth = $this->auth([]);

        $this->assertNull($auth->emailFromCode('', 'https://x.test/auth/callback'));
        $this->assertSame([], $this->sent);
    }

    public function testUnconfiguredClientNeverReachesGoogle(): void
    {
        $auth = $this->auth([], configured: false);

        $this->assertNull($auth->emailFromCode('code', 'https://x.test/auth/callback'));
        $this->assertSame([], $this->sent);
    }
}
