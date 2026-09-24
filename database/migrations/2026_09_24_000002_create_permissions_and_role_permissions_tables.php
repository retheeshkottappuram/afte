<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('slug', 60)->unique();
                $table->string('category', 60)->default('General');
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->string('role', 30)->index();
                $table->string('permission_slug', 60)->index();
                $table->timestamps();

                $table->unique(['role', 'permission_slug']);
            });
        }

        // Seed default permissions
        $permissions = [
            [
                'name' => 'Manage Automated Trading',
                'slug' => 'manage_trading',
                'category' => 'Automated Trading',
                'description' => 'Start/stop auto-trading bot, adjust kill switch, and close positions.',
            ],
            [
                'name' => 'View Trading Terminal',
                'slug' => 'view_trading',
                'category' => 'Automated Trading',
                'description' => 'View terminal balance, margin equity, open trades, and history.',
            ],
            [
                'name' => 'View Crypto Signals',
                'slug' => 'view_signals',
                'category' => 'CryptoLens Signals',
                'description' => 'Access real-time candlestick charts and confluence signal analysis.',
            ],
            [
                'name' => 'View Alert History',
                'slug' => 'view_alerts',
                'category' => 'CryptoLens Signals',
                'description' => 'Browse and filter historical Telegram signal alert logs.',
            ],
            [
                'name' => 'Run Market Scanner',
                'slug' => 'trigger_scans',
                'category' => 'CryptoLens Signals',
                'description' => 'Execute whole-market scans across Binance Futures perpetual pairs.',
            ],
            [
                'name' => 'Manage Sentinel Watcher',
                'slug' => 'manage_sentinel',
                'category' => 'CryptoLens Signals',
                'description' => 'Start/stop 24/7 background sentinel watcher and manage monitored coin lists.',
            ],
            [
                'name' => 'Send Telegram Alerts',
                'slug' => 'send_alerts',
                'category' => 'CryptoLens Signals',
                'description' => 'Dispatch manual and test trading signals directly to Telegram.',
            ],
            [
                'name' => 'Manage User Accounts',
                'slug' => 'manage_users',
                'category' => 'Administration',
                'description' => 'Create new users, modify user account details, and delete users.',
            ],
            [
                'name' => 'Manage Roles & Permissions',
                'slug' => 'manage_roles',
                'category' => 'Administration',
                'description' => 'Configure capabilities and access permissions for Admin and User roles.',
            ],
        ];

        $now = now();
        foreach ($permissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $p['slug']],
                array_merge($p, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // Assign all permissions to 'admin'
        foreach ($permissions as $p) {
            DB::table('role_permissions')->updateOrInsert(
                ['role' => 'admin', 'permission_slug' => $p['slug']],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // Assign default view permissions to 'user'
        $userPerms = ['view_trading', 'view_signals', 'view_alerts'];
        foreach ($userPerms as $slug) {
            DB::table('role_permissions')->updateOrInsert(
                ['role' => 'user', 'permission_slug' => $slug],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // Normalize any existing non-admin roles to 'user'
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            DB::table('users')->whereNotIn('role', ['admin', 'user'])->update(['role' => 'user']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
