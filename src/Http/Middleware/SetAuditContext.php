<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Vimatech\AuditLog\AuditContext;

final class SetAuditContext
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$actor, $guard] = $this->resolveActor($request);

        $this->context
            ->actingAs($actor, $guard)
            ->fromRequest(
                requestId: (string) ($request->header('X-Request-Id') ?: Str::uuid()),
                ip: config('audit-log.capture.ip', true) ? $request->ip() : null,
                userAgent: config('audit-log.capture.user_agent', true) ? $request->userAgent() : null,
            );

        return $next($request);
    }

    /** @return array{0: Model|null, 1: string|null} */
    private function resolveActor(Request $request): array
    {
        /** @var array<int, string>|null $guards */
        $guards = config('audit-log.guards');

        if ($guards === null) {
            $user = $request->user();

            return [$user instanceof Model ? $user : null, null];
        }

        foreach ($guards as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Model) {
                return [$user, $guard];
            }
        }

        return [null, null];
    }
}
