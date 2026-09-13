<?php

namespace App\Services;

use App\Models\AgentMarginCorrection;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

class AgentMarginCorrectionNoticeService
{
    public const SETTING_NOTICE_UNTIL = 'agent_margin_correction_notice_until';

    public function isNoticePeriodActive(): bool
    {
        $until = $this->noticeExpiresAt();

        return $until !== null && now()->lte($until);
    }

    public function noticeExpiresAt(): ?Carbon
    {
        $raw = Setting::getValue(self::SETTING_NOTICE_UNTIL);

        if ($raw === null || $raw === '') {
            return null;
        }

        return Carbon::parse($raw);
    }

    public function activateNoticePeriod(int $days = 7): void
    {
        if ($this->noticeExpiresAt() !== null) {
            return;
        }

        Setting::setValue(self::SETTING_NOTICE_UNTIL, now()->addDays($days)->toDateTimeString());
    }

    public function isMenuVisibleFor(User $agent): bool
    {
        if (! $this->isNoticePeriodActive()) {
            return false;
        }

        return AgentMarginCorrection::query()
            ->where('agent_user_id', $agent->id)
            ->where('clawback_amount', '>', 0)
            ->exists();
    }

    public function daysRemaining(): ?int
    {
        $until = $this->noticeExpiresAt();

        if ($until === null || now()->gt($until)) {
            return null;
        }

        return (int) max(0, now()->diffInDays($until, false));
    }
}
