<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('brand_name');
            $table->string('instagram_contact')->nullable()->after('telegram_contact');
            $table->string('whatsapp_contact')->nullable()->after('instagram_contact');
            $table->string('email_contact')->nullable()->after('phone_contact');
            $table->string('website_url')->nullable()->after('email_contact');
            $table->text('support_note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn([
                'tagline',
                'instagram_contact',
                'whatsapp_contact',
                'email_contact',
                'website_url',
                'support_note',
            ]);
        });
    }
};
