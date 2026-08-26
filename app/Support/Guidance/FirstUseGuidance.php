<?php

namespace App\Support\Guidance;

use App\Models\DashboardAcknowledgement;
use Illuminate\Http\Request;

final class FirstUseGuidance
{
    public const SESSION_KEY = 'contextual_guidance';
    public const SURFACE = 'crm_first_use_guidance';
    public const TYPE_SETTING_LOCATION = 'setting_location';

    /**
     * @param array{
     *     title: string,
     *     message: string,
     *     action_label: string,
     *     action_url: string
     * } $guidance
     */
    public function flashOnce(
        Request $request,
        string $key,
        array $guidance,
    ): bool {
        $userId = $request->user()?->getKey();

        if (! is_numeric($userId)) {
            return false;
        }

        $key = DashboardAcknowledgement::normalizeItemKey($key);
        $alreadyShown = DashboardAcknowledgement::query()
            ->where('user_id', (int) $userId)
            ->surface(self::SURFACE)
            ->item(self::TYPE_SETTING_LOCATION, $key)
            ->active()
            ->exists();

        if ($alreadyShown) {
            return false;
        }

        DashboardAcknowledgement::query()->updateOrCreate(
            [
                'user_id' => (int) $userId,
                'surface' => self::SURFACE,
                'item_type' => self::TYPE_SETTING_LOCATION,
                'item_key' => $key,
            ],
            [
                'acknowledged_at' => now(),
                'expires_at' => null,
            ],
        );

        $request->session()->flash(self::SESSION_KEY, $guidance);

        return true;
    }
}