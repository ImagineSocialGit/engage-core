<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record successful access to sensitive CRM data without duplicating PII in logs.
 * Operational log retention and collection remain deployment responsibilities.
 */
final class AuditProtectedDataRead
{
    public function handle(Request $request, Closure $next, string $resource, string $action): Response
    {
        $response = $next($request);
        $user = $request->user();

        $isRead = $request->isMethod('GET') && in_array($action, ['list', 'view', 'lookup'], true);
        $isExport = $request->isMethod('POST') && $action === 'export';

        if ($response->getStatusCode() !== Response::HTTP_OK
            || ! $user instanceof User
            || (! $isRead && ! $isExport)
        ) {
            return $response;
        }

        $routeParameter = match ($resource) {
            'contact' => 'contact',
            'commerce_order' => 'commerceOrder',
            default => null,
        };

        if (! in_array($resource, ['contact', 'commerce_order', 'commerce_privacy'], true)) {
            return $response;
        }

        $record = $action === 'view' ? $request->route($routeParameter) : null;
        $recordId = $record instanceof Model ? (int) $record->getKey() : null;

        if ($action === 'view' && (! is_int($recordId) || $recordId <= 0)) {
            return $response;
        }

        // Export responses stream later; this audit records authorization to
        // initiate the export, not proof that a download finished successfully.
        Log::channel('personal_data_access')->info(
            $isExport ? 'protected_data_export_initiated' : 'protected_record_read',
            [
                'client_key' => (string) config('client.key', ''),
                'actor_user_id' => (int) $user->getKey(),
                'resource' => $resource,
                'action' => $action,
                'record_id' => $recordId,
            ],
        );

        return $response;
    }
}
