<?php

require __DIR__.'/src/Utils/helpers.php';

return [
    App\Events\PluginWasEnabled::class => function () {
        if (! Schema::hasTable('uuid')) {
            Schema::create('uuid', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('uuid', 255);
            });
        }

        if (! Schema::hasTable('ygg_log')) {
            Schema::create('ygg_log', function ($table) {
                $table->increments('id');
                $table->string('action');
                $table->integer('user_id');
                $table->integer('player_id');
                $table->string('parameters')->default('');
                $table->string('ip')->default('');
                $table->dateTime('time');
            });
        }

        if (! Schema::hasTable('mojang_verifications')) {
            Schema::create('mojang_verifications', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unique();
                $table->string('mojang_uuid', 32)->unique();
            });
        }

        if (! Schema::hasTable('pending_mojang_bind')) {
            Schema::create('pending_mojang_bind', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unique();
                $table->string('mojang_name', 16);
                $table->string('mojang_uuid', 32)->nullable();
                $table->dateTime('created_at');
            });
        } elseif (! Schema::hasColumn('pending_mojang_bind', 'mojang_uuid')) {
            // 旧版本升级：补上绑定申请时解析出的正版 UUID 列
            Schema::table('pending_mojang_bind', function ($table) {
                $table->string('mojang_uuid', 32)->nullable();
            });
        }

        $items = [
            'ygg_uuid_algorithm' => 'v3',
            'ygg_token_expire_1' => '259200', // 3 days
            'ygg_token_expire_2' => '604800', // 7 days
            'ygg_rate_limit' => '1000',
            'ygg_skin_domain' => '',
            'ygg_search_profile_max' => '5',
            'ygg_private_key' => '',
            'ygg_show_config_section' => 'true',
            'ygg_show_activities_section' => 'true',
            'ygg_enable_ali' => 'true',
        ];

        foreach ($items as $key => $value) {
            if (! Option::get($key)) {
                Option::set($key, $value);
            }
        }

        $originalDefaultValue = [
            'ygg_token_expire_1' => '600',
            'ygg_token_expire_2' => '1200'
        ];

        // 原来的令牌过期时间默认值太低了，调高点
        foreach ($originalDefaultValue as $key => $value) {
            if (Option::get($key) == $value) {
                Option::set($key, $items[$key]);
            }
        }

        if (! env('YGG_VERBOSE_LOG')) {
            @unlink(ygg_log_path());
            @unlink(storage_path('logs/yggdrasil.log'));
        }
    },
];
