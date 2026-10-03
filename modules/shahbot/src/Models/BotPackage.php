<?php

namespace Modules\ShahBot\Models;

use App\Models\PackageDuration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک تعرفه در فروشگاه یک ربات نمایندگی: دیده بشود یا نه، و با چه قیمتی.
 */
class BotPackage extends Model
{
    protected $table = 'shahbot_bot_packages';

    protected $fillable = ['bot_id', 'package_duration_id', 'is_enabled', 'display_price', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'display_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(BotInstance::class, 'bot_id');
    }

    public function duration(): BelongsTo
    {
        return $this->belongsTo(PackageDuration::class, 'package_duration_id');
    }
}
