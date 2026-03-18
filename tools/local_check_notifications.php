<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;

echo "HAS_TABLE_notifications=" . (Schema::hasTable('notifications') ? '1' : '0') . "\n";
echo "HAS_TABLE_admin_notifications=" . (Schema::hasTable('admin_notifications') ? '1' : '0') . "\n";

if (Schema::hasTable('notifications')) {
    $total = DB::table('notifications')->count();
    echo "NOTIFICATIONS_TOTAL={$total}\n";
    $top = DB::table('notifications')
        ->select('notifiable_id', DB::raw('count(*) as c'))
        ->groupBy('notifiable_id')
        ->orderByDesc('c')
        ->limit(10)
        ->get();
    echo "TOP_NOTIFIABLE_IDS:\n";
    foreach ($top as $row) {
        $u = User::find($row->notifiable_id);
        $email = $u?->email ?? '';
        echo "- user_id={$row->notifiable_id} count={$row->c} email={$email}\n";
    }
} else {
    echo "⚠️ Table 'notifications' absente => le canal database ne peut pas alimenter l'UI.\n";
}

