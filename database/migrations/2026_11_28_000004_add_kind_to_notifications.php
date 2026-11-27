<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Lab 7: notifications are written by Lambda functions that may receive a message twice
// (SQS delivers at least once). "kind" says which email it is (sealed / opened), and the
// unique index lets the functions insert with ON CONFLICT (capsule_id, kind) DO NOTHING.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS kind varchar(20)");
        DB::statement("UPDATE notifications SET kind = 'opened' WHERE kind IS NULL AND message LIKE 'Your capsule % is now open.'");
        DB::statement("UPDATE notifications SET kind = 'sealed' WHERE kind IS NULL AND message LIKE 'Capsule % was sealed for you until %'");
        // Rows of unknown kind or duplicates written by the old code keep a unique legacy kind.
        DB::statement("UPDATE notifications n SET kind = 'legacy-' || n.id
                        WHERE n.kind IS NULL OR EXISTS (SELECT 1 FROM notifications d
                              WHERE d.capsule_id = n.capsule_id AND d.kind = n.kind AND d.id < n.id)");
        DB::statement("ALTER TABLE notifications ALTER COLUMN kind SET NOT NULL");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS notifications_capsule_kind_uq ON notifications (capsule_id, kind)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_capsule_kind_uq');
        DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS kind');
    }
};
