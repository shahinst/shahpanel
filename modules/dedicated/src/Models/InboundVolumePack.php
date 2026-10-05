<?php

namespace Modules\Dedicated\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Volume the admin sells to inbound agents on one server.
 */
class InboundVolumePack extends Model
{
    protected $fillable = ['server_id', 'title', 'gb', 'price', 'currency', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['gb' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
