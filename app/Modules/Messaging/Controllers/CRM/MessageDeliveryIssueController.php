<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\DeliveryIssues\MessageDeliveryIssueReviewService;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class MessageDeliveryIssueController extends Controller
{
    private const RESOLUTION_REASONS = [
        'destination_verified',
        'provider_issue_resolved',
        'manual_review_resolved',
    ];

    public function index(
        Request $request,
        MessageDeliveryIssueReviewService $issues,
    ): View {
        $suppressions = $issues->query()
            ->paginate(50)
            ->withQueryString();

        return view('crm.messaging.delivery-issues.index', [
            'title' => 'Messaging Delivery Issues',
            'heading' => 'Messaging Delivery Issues',
            'suppressions' => $suppressions,
            'deliveryIssues' => $issues->present($suppressions->getCollection()),
        ]);
    }

    public function release(
        Request $request,
        MessageSuppression $messageSuppression,
        MessageDeliveryIssueReviewService $issues,
        MessageSuppressionService $suppressions,
    ): RedirectResponse {
        $validated = $request->validate([
            'resolution_reason' => [
                'required',
                'string',
                Rule::in(self::RESOLUTION_REASONS),
            ],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! $messageSuppression->isActive()) {
            throw ValidationException::withMessages([
                'resolution_reason' => 'This suppression has already been released.',
            ]);
        }

        if (! $issues->isCurrentIssue($messageSuppression)) {
            throw ValidationException::withMessages([
                'resolution_reason' => 'This destination no longer matches current Contact information and does not need release.',
            ]);
        }

        if (! $issues->canRelease($messageSuppression)) {
            throw ValidationException::withMessages([
                'resolution_reason' => 'Complaint suppressions cannot be released from the delivery-issue review surface.',
            ]);
        }

        $released = $suppressions->release(
            channel: $messageSuppression->channel,
            destination: $messageSuppression->destination,
            provider: $messageSuppression->provider,
            sourceEventId: null,
            meta: [
                'source' => 'crm_delivery_issue_review',
                'actor_user_id' => $request->user()?->getKey(),
                'resolution_reason' => $validated['resolution_reason'],
                'message_suppression_id' => $messageSuppression->getKey(),
            ],
        );

        if (! $released instanceof MessageSuppression) {
            throw ValidationException::withMessages([
                'resolution_reason' => 'The active suppression could not be released.',
            ]);
        }

        return redirect($this->safeReturnTo($validated['return_to'] ?? null))
            ->with('success', 'Messaging suppression released.');
    }

    private function safeReturnTo(?string $returnTo): string
    {
        $fallback = route('crm.messaging.delivery-issues.index');

        if (! is_string($returnTo)
            || trim($returnTo) === ''
            || preg_match('/[\x00-\x1F\x7F]/', $returnTo) === 1
        ) {
            return $fallback;
        }

        $returnTo = trim($returnTo);

        if (! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, '\\')
        ) {
            return $fallback;
        }

        $decoded = $returnTo;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || str_contains($decoded, '\\')
                || str_starts_with($decoded, '//')
            ) {
                return $fallback;
            }

            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                return str_starts_with($decoded, '/') ? $returnTo : $fallback;
            }

            $decoded = $next;
        }

        return $fallback;
    }
}