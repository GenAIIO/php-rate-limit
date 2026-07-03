<?php

namespace GenAI\RateLimit\Interceptor;

use GenAI\Http\Response;
use GenAI\RateLimit\RateLimiter;
use GenAI\Web\Interceptor\Interceptor;
use GenAI\Web\Interceptor\RequestHandler;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Throttles state-changing requests (POST/PUT/PATCH/DELETE) per client IP and
 * path. Each endpoint gets its own bucket (the key is "<ip>|<path>"), so a flood
 * of POST /login is limited independently of POST /forgot. Over the limit -> 429;
 * otherwise the chain continues. Safe methods (GET/HEAD/OPTIONS) pass through, so
 * normal page browsing is never throttled.
 *
 * The client IP comes from REMOTE_ADDR (not spoofable by the client). If you sit
 * behind a trusted reverse proxy, override clientIp() to read a vetted forwarded
 * header — never trust X-Forwarded-For blindly, or an attacker just rotates it.
 *
 * Like CsrfInterceptor, this base is NOT itself an #[Intercept] — an app enables
 * and scopes it with a thin subclass:
 *
 *   #[Intercept]                       // all requests; only POST-likes are counted
 *   class Throttle extends \GenAI\RateLimit\Interceptor\RateLimitInterceptor {}
 *
 *   #[Intercept(path: '/login')]       // or scope to one path
 *   class LoginThrottle extends \GenAI\RateLimit\Interceptor\RateLimitInterceptor {}
 *
 * Runtime class (PHP 5.3-safe).
 */
class RateLimitInterceptor implements Interceptor
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function intercept(ServerRequestInterface $request, RequestHandler $next)
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH' && $method !== 'DELETE') {
            return null; // safe method -> not counted
        }

        $key = $this->clientIp($request) . '|' . $request->getUri()->getPath();
        if ($this->limiter->tooMany($key)) {
            return $this->limitedResponse($request);
        }

        return null;
    }

    /**
     * The 429 to return when over the limit. Default: a plain UTF-8 message.
     * Override in a subclass to render a branded page (see App\Interceptor\Throttle).
     */
    protected function limitedResponse(ServerRequestInterface $request)
    {
        // Localize when genai/i18n is wired (key 'ratelimit.too_many'); else English.
        $msg = 'Too many requests — please slow down and try again in a minute.';
        if (function_exists('__')) {
            $t = __('ratelimit.too_many');
            if ($t !== '' && $t !== 'ratelimit.too_many') {
                $msg = $t;
            }
        }
        return new Response(
            $msg,
            429,
            array(
                'Retry-After'  => (string) $this->limiter->getWindow(),
                'Content-Type' => 'text/plain; charset=UTF-8',   // message has a non-ASCII dash
            )
        );
    }

    /** Override in a subclass if you terminate TLS behind a trusted proxy. */
    protected function clientIp(ServerRequestInterface $request)
    {
        $server = $request->getServerParams();
        return isset($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : 'unknown';
    }
}
