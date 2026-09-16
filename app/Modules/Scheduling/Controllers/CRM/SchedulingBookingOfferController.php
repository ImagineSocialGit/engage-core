<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingBookingOffer;
use App\Modules\Scheduling\Services\BookingEligibilityProviderRegistry;
use App\Modules\Scheduling\Services\SchedulingBookingOfferReadService;
use App\Modules\Scheduling\Services\SchedulingSetupProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SchedulingBookingOfferController extends Controller
{
    public function index(
        BookableService $bookableService,
        SchedulingBookingOfferReadService $offers,
        BookingEligibilityProviderRegistry $eligibilityProviders,
        SchedulingSetupProgress $progress,
    ): View {
        $providerDefinitions = $eligibilityProviders->authoringDefinitions();

        return view('crm.scheduling.services.booking-offers', [
            'title' => 'Offers · '.$bookableService->name,
            'heading' => 'Booking offers',
            'service' => $bookableService,
            'setupProgress' => $progress->forService($bookableService, 'offers'),
            'offers' => $offers->forService($bookableService)
                ->map(fn (SchedulingBookingOffer $offer): array => $this->offerRow(
                    $offer,
                    $providerDefinitions,
                ))
                ->values()
                ->all(),
            'eligibilityProviders' => $providerDefinitions,
            'blankRewardRows' => array_fill(0, 3, [
                'name' => '',
                'max_claim_number' => '',
                'contact_tag' => '',
            ]),
            'clientTimezone' => (string) config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
        ]);
    }

    public function store(
        Request $request,
        BookableService $bookableService,
        BookingEligibilityProviderRegistry $eligibilityProviders,
    ): RedirectResponse {
        $validated = $this->validated(
            request: $request,
            service: $bookableService,
        );

        $this->persist(
            service: $bookableService,
            offer: null,
            validated: $validated,
            eligibilityProviders: $eligibilityProviders,
        );

        return redirect()
            ->route('crm.scheduling.configuration.services.offers.index', $bookableService)
            ->with('success', 'Booking offer added.');
    }

    public function update(
        Request $request,
        BookableService $bookableService,
        SchedulingBookingOffer $bookingOffer,
        BookingEligibilityProviderRegistry $eligibilityProviders,
    ): RedirectResponse {
        $this->assertOfferBelongsToService($bookableService, $bookingOffer);
        $validated = $this->validated(
            request: $request,
            service: $bookableService,
            offer: $bookingOffer,
        );

        $this->persist(
            service: $bookableService,
            offer: $bookingOffer,
            validated: $validated,
            eligibilityProviders: $eligibilityProviders,
        );

        return redirect()
            ->route('crm.scheduling.configuration.services.offers.index', $bookableService)
            ->with('success', 'Booking offer updated.');
    }

    public function destroy(
        BookableService $bookableService,
        SchedulingBookingOffer $bookingOffer,
    ): RedirectResponse {
        $this->assertOfferBelongsToService($bookableService, $bookingOffer);

        if ($bookingOffer->claims()->exists()) {
            throw ValidationException::withMessages([
                'offer' => 'An offer with completed claims cannot be deleted. Turn it off instead.',
            ]);
        }

        $bookingOffer->delete();

        return redirect()
            ->route('crm.scheduling.configuration.services.offers.index', $bookableService)
            ->with('success', 'Booking offer removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(
        Request $request,
        BookableService $service,
        ?SchedulingBookingOffer $offer = null,
    ): array {
        return $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'code' => [
                'required',
                'string',
                'max:40',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{2,39}\z/',
                Rule::unique('scheduling_booking_offers', 'code')
                    ->where(fn ($query) => $query->where(
                        'bookable_service_id',
                        $service->getKey(),
                    ))
                    ->ignore($offer?->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'eligibility_provider' => ['nullable', 'string', 'max:80'],
            'eligibility_option' => ['nullable', 'string', 'max:191'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
            'claim_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'ineligible_message' => ['nullable', 'string', 'max:1000'],
            'exhausted_message' => ['nullable', 'string', 'max:1000'],
            'rewards' => ['nullable', 'array', 'max:5'],
            'rewards.*.name' => ['nullable', 'string', 'max:255'],
            'rewards.*.max_claim_number' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'rewards.*.contact_tag' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function persist(
        BookableService $service,
        ?SchedulingBookingOffer $offer,
        array $validated,
        BookingEligibilityProviderRegistry $eligibilityProviders,
    ): void {
        $providerKey = is_string($validated['eligibility_provider'] ?? null)
            ? trim((string) $validated['eligibility_provider'])
            : '';
        $optionValue = is_string($validated['eligibility_option'] ?? null)
            ? trim((string) $validated['eligibility_option'])
            : '';
        $criteria = null;

        if ($providerKey !== '' || $optionValue !== '') {
            if ($providerKey === '' || $optionValue === '') {
                throw ValidationException::withMessages([
                    'eligibility_option' => 'Choose both an eligibility type and its qualifying audience.',
                ]);
            }

            try {
                $criteria = $eligibilityProviders->criteriaForOption(
                    provider: $providerKey,
                    value: $optionValue,
                );
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'eligibility_option' => $exception->getMessage(),
                ]);
            }
        }

        $rewards = collect($validated['rewards'] ?? [])
            ->filter(fn (mixed $reward): bool => is_array($reward)
                && trim((string) ($reward['name'] ?? '')) !== '')
            ->values();
        $claimLimit = isset($validated['claim_limit'])
            ? (int) $validated['claim_limit']
            : null;

        foreach ($rewards as $index => $reward) {
            $threshold = (int) ($reward['max_claim_number'] ?? 0);

            if ($threshold < 1) {
                throw ValidationException::withMessages([
                    "rewards.{$index}.max_claim_number" => 'Each named reward needs a first-N threshold.',
                ]);
            }

            if ($claimLimit !== null && $threshold > $claimLimit) {
                throw ValidationException::withMessages([
                    "rewards.{$index}.max_claim_number" => 'Reward thresholds cannot exceed the offer claim limit.',
                ]);
            }
        }

        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));

        try {
            DB::transaction(function () use (
                $service,
                $offer,
                $validated,
                $providerKey,
                $criteria,
                $rewards,
                $timezone,
            ): void {
                $offer ??= new SchedulingBookingOffer();
                $offer->forceFill([
                    'bookable_service_id' => $service->getKey(),
                    'code' => SchedulingBookingOffer::normalizeCode((string) $validated['code']),
                    'name' => trim((string) $validated['name']),
                    'status' => ! empty($validated['enabled'])
                        ? SchedulingBookingOffer::STATUS_ACTIVE
                        : SchedulingBookingOffer::STATUS_INACTIVE,
                    'starts_at' => $this->utcDateTime($validated['starts_at'] ?? null, $timezone),
                    'ends_at' => $this->utcDateTime($validated['ends_at'] ?? null, $timezone),
                    'claim_limit' => $validated['claim_limit'] ?? null,
                    'ineligible_message' => $this->nullableString($validated['ineligible_message'] ?? null),
                    'exhausted_message' => $this->nullableString($validated['exhausted_message'] ?? null),
                ])->save();

                $offer->conditions()->delete();

                if ($criteria !== null) {
                    $offer->conditions()->create([
                        'provider' => $providerKey,
                        'criteria' => $criteria,
                        'sort_order' => 0,
                    ]);
                }

                $offer->rewards()->delete();

                foreach ($rewards as $index => $reward) {
                    $createdReward = $offer->rewards()->create([
                        'name' => trim((string) $reward['name']),
                        'max_claim_number' => (int) $reward['max_claim_number'],
                        'sort_order' => $index,
                    ]);
                    $tag = $this->nullableString($reward['contact_tag'] ?? null);

                    if ($tag !== null) {
                        $createdReward->actions()->create([
                            'provider' => 'contact_tag',
                            'payload' => ['tag' => $tag],
                            'sort_order' => 0,
                        ]);
                    }
                }
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'code' => 'That offer code is already used by this appointment type.',
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $providerDefinitions
     * @return array<string, mixed>
     */
    private function offerRow(
        SchedulingBookingOffer $offer,
        array $providerDefinitions,
    ): array {
        $condition = $offer->conditions->first();
        $providerKey = $condition?->provider ?? '';
        $criteria = is_array($condition?->criteria) ? $condition->criteria : [];
        $optionValue = '';

        foreach ($providerDefinitions as $provider) {
            if (($provider['key'] ?? null) !== $providerKey) {
                continue;
            }

            foreach ($provider['options'] ?? [] as $option) {
                if (is_array($option)
                    && is_array($option['criteria'] ?? null)
                    && $option['criteria'] == $criteria
                ) {
                    $optionValue = (string) ($option['value'] ?? '');
                    break 2;
                }
            }
        }

        return [
            'model' => $offer,
            'eligibility_provider' => $providerKey,
            'eligibility_option' => $optionValue,
            'rewards' => $offer->rewards
                ->map(function ($reward): array {
                    $tagAction = $reward->actions->firstWhere('provider', 'contact_tag');

                    return [
                        'name' => $reward->name,
                        'max_claim_number' => $reward->max_claim_number,
                        'contact_tag' => is_array($tagAction?->payload)
                            ? ($tagAction->payload['tag'] ?? '')
                            : '',
                    ];
                })
                ->pad(3, [
                    'name' => '',
                    'max_claim_number' => '',
                    'contact_tag' => '',
                ])
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    private function assertOfferBelongsToService(
        BookableService $service,
        SchedulingBookingOffer $offer,
    ): void {
        abort_unless(
            (int) $offer->bookable_service_id === (int) $service->getKey(),
            404,
        );
    }

    private function utcDateTime(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d\\TH:i', trim($value), $timezone)
            ->utc();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}