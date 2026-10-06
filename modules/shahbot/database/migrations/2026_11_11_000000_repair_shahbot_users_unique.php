<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * add_shahbot_agents added bot_id and swapped unique(telegram_id) for
 * unique(bot_id, telegram_id) in two steps. MySQL cannot roll DDL back, so an
 * update cut off between them left the column without the swap, and its
 * bot_id check skipped the swap on every later run. The old index then let a
 * Telegram user join only one bot: their /start to a second agent's bot failed
 * on the duplicate and went unanswered. This repairs it from the real indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shahbot_users', 'bot_id')) {
            return;
        }

        $uniques = collect(Schema::getIndexes('shahbot_users'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']]);

        Schema::table('shahbot_users', function (Blueprint $table) use ($uniques): void {
            foreach ($uniques->filter(fn (array $columns): bool => $columns === ['telegram_id'])->keys() as $name) {
                $table->dropUnique($name);
            }

            if (! $uniques->contains(['bot_id', 'telegram_id'])) {
                $table->unique(['bot_id', 'telegram_id']);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: the indexes are what add_shahbot_agents meant.
    }
};
