<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agents in the bot:
 *  - shahbot_bots: a sales bot of an agent or seller of the panel, next to the
 *    main bot. Each bot has its own users; bot_id 0 is the main bot.
 *  - reseller_user_id: the panel seller account a bot user became after an
 *    approved agency request (wholesale and bulk buying in the bot).
 *  - shahbot_agency_requests: those requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_bots')) {
            Schema::create('shahbot_bots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->text('token_enc')->nullable();
                $table->string('username', 64)->nullable();
                $table->string('webhook_secret', 64)->unique();
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('shahbot_users', 'bot_id')) {
            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->unsignedBigInteger('bot_id')->default(0)->after('id');
            });

            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->dropUnique(['telegram_id']);
                $table->unique(['bot_id', 'telegram_id']);
            });
        }

        if (! Schema::hasColumn('shahbot_users', 'reseller_user_id')) {
            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->foreignId('reseller_user_id')->nullable()->after('client_user_id')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('shahbot_agency_requests')) {
            Schema::create('shahbot_agency_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->text('note')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reviewed_by', 128)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('shahbot_broadcasts', 'bot_id')) {
            Schema::table('shahbot_broadcasts', function (Blueprint $table): void {
                $table->unsignedBigInteger('bot_id')->default(0)->after('id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_agency_requests');

        if (Schema::hasColumn('shahbot_users', 'reseller_user_id')) {
            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reseller_user_id');
            });
        }

        if (Schema::hasColumn('shahbot_users', 'bot_id')) {
            DB::table('shahbot_users')->where('bot_id', '!=', 0)->delete();
            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->dropUnique(['bot_id', 'telegram_id']);
                $table->unique('telegram_id');
                $table->dropColumn('bot_id');
            });
        }

        if (Schema::hasColumn('shahbot_broadcasts', 'bot_id')) {
            Schema::table('shahbot_broadcasts', fn (Blueprint $table) => $table->dropColumn('bot_id'));
        }

        Schema::dropIfExists('shahbot_bots');
    }
};
