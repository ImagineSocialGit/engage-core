<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->string('reply_semantic_category', 32)
                ->nullable()
                ->after('reply_intent_key')
                ->index();
            $table->string('reply_semantic_interest', 16)
                ->nullable()
                ->after('reply_semantic_category');
            $table->string('reply_semantic_readiness', 16)
                ->nullable()
                ->after('reply_semantic_interest');
            $table->string('reply_semantic_requested_action', 32)
                ->nullable()
                ->after('reply_semantic_readiness');
            $table->string('reply_semantic_constraint', 32)
                ->nullable()
                ->after('reply_semantic_requested_action');
            $table->string('reply_semantic_confidence', 16)
                ->nullable()
                ->after('reply_semantic_constraint');
            $table->string('reply_semantic_source', 32)
                ->nullable()
                ->after('reply_semantic_confidence');
            $table->string('reply_semantic_rule_key', 64)
                ->nullable()
                ->after('reply_semantic_source');
            $table->timestamp('reply_semantic_assessed_at')
                ->nullable()
                ->after('reply_semantic_rule_key');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['reply_semantic_category']);
            $table->dropColumn([
                'reply_semantic_category',
                'reply_semantic_interest',
                'reply_semantic_readiness',
                'reply_semantic_requested_action',
                'reply_semantic_constraint',
                'reply_semantic_confidence',
                'reply_semantic_source',
                'reply_semantic_rule_key',
                'reply_semantic_assessed_at',
            ]);
        });
    }
};