<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;

class BotTutorial extends Model
{
    protected $table = 'shahbot_tutorials';

    protected $fillable = ['title', 'body', 'url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
