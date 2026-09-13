<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'is_hub')) {
                $table->boolean('is_hub')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('servers', 'wan_interface')) {
                $table->string('wan_interface', 64)->default('ether1')->after('is_hub');
            }
            if (! Schema::hasColumn('servers', 'api_ssl')) {
                $table->boolean('api_ssl')->default(false)->after('wan_interface');
            }
        });

        Schema::create('crm_tunnels', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16)->default('gre');
            $table->foreignId('hub_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('exit_server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('tunnel_subnet', 18);
            $table->string('hub_tunnel_ip', 45);
            $table->string('exit_tunnel_ip', 45);
            $table->string('keepalive', 16)->default('10s,3');
            $table->string('comment_tag', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('last_tested_at')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();

            $table->unique('tunnel_subnet');
            $table->index(['status', 'hub_server_id']);
        });

        Schema::create('crm_tunnel_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crm_tunnel_id')->constrained('crm_tunnels')->cascadeOnDelete();
            $table->string('action', 16);
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->boolean('success')->default(false);
            $table->longText('output')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['crm_tunnel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tunnel_logs');
        Schema::dropIfExists('crm_tunnels');

        Schema::table('servers', function (Blueprint $table): void {
            foreach (['api_ssl', 'wan_interface', 'is_hub'] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
