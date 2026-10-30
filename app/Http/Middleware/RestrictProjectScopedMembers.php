<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Members restricted to assigned projects only reach their projects, their profile and what those
 * pages need. Everything else (servers, proxy, sources, keys, shared variables, notifications,
 * team settings, the canvas API, ...) answers 403, because those pages list team-wide data.
 */
class RestrictProjectScopedMembers
{
    /** Route names a restricted member may open. Project pages check project access in their policies. */
    private const ALLOWED_ROUTES = [
        'dashboard',
        'project.*',
        'profile',
        'profile.*',
        'team.select',
        'team.invitation.*',
        'team.avatar',
        'team.member.avatar',
        'auth.*',
        'verify.*',
        'two-factor.*',
        'password.*',
        'terminal.auth',
        'terminal.auth.ips',
        'download.backup',
        'download.volume-backup',
        'upload.backup',
        '*livewire*',
        'dev.*',
    ];

    /** Project routes that list servers, destinations or other team-wide data. */
    private const DENIED_ROUTES = [
        'project.canvas*',
        'project.environment.canvas',
        'project.resource.create',
        'project.clone-me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $team = $user?->currentTeam();

        if (! $team || ! $user->isRestrictedInTeam($team->id) || $this->allows($request)) {
            return $next($request);
        }

        abort(403, 'Your access is limited to the projects assigned to you.');
    }

    private function allows(Request $request): bool
    {
        if ($request->is('canvas/api/*')) {
            return false;
        }

        $name = $request->route()?->getName();
        if ($name === null) {
            // Unnamed routes: backup settings under project pages, the realtime check and the 404 fallback.
            return $request->is('project/*', 'realtime') || $request->route()?->isFallback;
        }

        return Str::is(self::ALLOWED_ROUTES, $name) && ! Str::is(self::DENIED_ROUTES, $name);
    }
}
