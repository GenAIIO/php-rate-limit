# genai/rate-limit

Fixed-window rate limiting for the GenAI stack — a small `RateLimiter` over a
pluggable store, plus a drop-in `RateLimitInterceptor` that returns **429** when a
client IP exceeds the limit on a path. Brute-force and flood protection without a
line of glue in your controllers. PHP 5.3.29-safe at runtime.

## How it counts

A fixed window: time is sliced into `window`-second buckets; each `(key, bucket)`
pair has a counter. The interceptor keys on `"<ip>|<path>"`, so every endpoint is
throttled independently — `POST /login` floods don't burn `POST /forgot`'s budget.
Only state-changing methods (POST/PUT/PATCH/DELETE) are counted, so normal page
browsing (GET) is never throttled.

## Use it

1. Configure (`app.ini`) — optional; defaults are 20 requests / 60s:

   ```ini
   [ratelimit]
   limit  = 20
   window = 60
   path   = cache/ratelimit
   ```

2. Expose the limiter as a bean:

   ```php
   #[Configuration]
   class RateLimitConfig {
       #[Bean(RateLimiter::class)]
       public function rateLimiter(RateLimitProperty $cfg) {
           return RateLimitFactory::build($cfg);
       }
   }
   ```

3. Enable the interceptor with a thin subclass (it stays opt-in, like CsrfInterceptor):

   ```php
   #[Intercept]   // all requests; only POST-likes are counted
   class Throttle extends \GenAI\RateLimit\Interceptor\RateLimitInterceptor {}
   ```

   Scope it to specific paths with `#[Intercept(path: '/login')]`, or run several
   subclasses for different endpoints.

## Account lockout (failed logins)

`RateLimiter` is fixed-window flood control. For "lock the account after N wrong
passwords," use `AttemptLimiter` — it counts *failures* per key (e.g. an email) and
locks for a fixed duration once the threshold is hit; a success clears it.

```php
#[Bean(AttemptLimiter::class)]
public function loginLockout(RateLimitProperty $cfg) {
    return RateLimitFactory::lockout($cfg);   // [ratelimit] login_max_fails / login_lock
}
```

```php
if (($wait = $lockout->lockedFor($email)) > 0) {
    return "locked — try again in ~" . ceil($wait / 60) . " min";
}
if ($passwordOk) { $lockout->clear($email); }   // reset on success
else             { $lockout->fail($email);  }    // count the failure
```

`lockedFor()` returns seconds remaining (0 = open); `remaining()` gives attempts
left before a lock, for "N tries left" messaging.

> **Tradeoff:** locking by email lets an attacker lock a victim out on purpose by
> failing 3× against their address. That's inherent to account lockout. If that
> matters, key on `email|ip` instead — at the cost of letting IP-rotation retry.

## Notes

- **Client IP** comes from `REMOTE_ADDR` (not client-spoofable). Behind a trusted
  reverse proxy, override `clientIp()` to read a *vetted* forwarded header — never
  trust `X-Forwarded-For` blindly.
- **FileStore** is single-server. Counters are per-`(key, window)` files swept
  opportunistically. For a cluster, implement `RateStore` against a shared backend
  (DB / Redis / APCu) and pass it to `RateLimiter` directly.
- **Fail-open**: if the store can't be read/written, requests are allowed — a
  broken counter never locks users out.

## Layers (use any without the others)

- `RateLimiter` + `RateStore`/`FileStore` — standalone; no web stack required.
- `RateLimitInterceptor` — needs `genai/web` (the `Interceptor` contract) and
  `genai/http` (the 429 `Response`); both are `suggest`, loaded only if you use it.
