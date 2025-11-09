<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$userId = 94;

echo "=== Vérification des commandes validées pour userId: $userId ===\n\n";

// 1. Commandes validées où user_id = 94
$ordersByUserId = \App\Models\Order::where('user_id', $userId)
    ->where('status', 'validated')
    ->get(['id', 'order_number', 'status', 'user_id', 'order_type']);
echo "1. Commandes validées avec user_id = $userId: " . $ordersByUserId->count() . "\n";
foreach ($ordersByUserId as $order) {
    echo "   - Order #$order->id ($order->order_number): status=$order->status, type=$order->order_type\n";
}

// 2. Commandes validées où userId 94 est dans order_employees
$ordersByEmployee = \App\Models\OrderEmployee::where('employee_id', $userId)
    ->with(['order' => function($q) {
        $q->where('status', 'validated')
          ->select('id', 'order_number', 'status', 'user_id', 'order_type');
    }])
    ->get()
    ->filter(function($oe) {
        return $oe->order && $oe->order->status === 'validated';
    });
echo "\n2. Commandes validées où userId $userId est dans order_employees: " . $ordersByEmployee->count() . "\n";
foreach ($ordersByEmployee as $oe) {
    $order = $oe->order;
    echo "   - Order #$order->id ($order->order_number): status=$order->status, type=$order->order_type, user_id=$order->user_id\n";
    echo "     OrderEmployee: employee_id=$oe->employee_id, card_design_type=$oe->card_design_type, card_design_number=$oe->card_design_number\n";
}

// 3. Toutes les commandes validées (pour référence)
$allValidatedOrders = \App\Models\Order::where('status', 'validated')
    ->get(['id', 'order_number', 'status', 'user_id', 'order_type']);
echo "\n3. Toutes les commandes validées: " . $allValidatedOrders->count() . "\n";
foreach ($allValidatedOrders as $order) {
    echo "   - Order #$order->id ($order->order_number): user_id=$order->user_id, type=$order->order_type\n";
}

// 4. Vérifier order_employees pour les commandes validées avec user_id != 94
$otherValidatedOrders = \App\Models\Order::where('status', 'validated')
    ->where('user_id', '!=', $userId)
    ->with(['orderEmployees' => function($q) use ($userId) {
        $q->where('employee_id', $userId)
          ->select('id', 'order_id', 'employee_id', 'card_design_type', 'card_design_number', 'no_design_yet');
    }])
    ->get(['id', 'order_number', 'status', 'user_id', 'order_type']);
echo "\n4. Commandes validées avec user_id != $userId mais où userId $userId est dans order_employees:\n";
$found = false;
foreach ($otherValidatedOrders as $order) {
    if ($order->orderEmployees->isNotEmpty()) {
        $found = true;
        echo "   - Order #$order->id ($order->order_number): user_id=$order->user_id, type=$order->order_type\n";
        foreach ($order->orderEmployees as $oe) {
            echo "     OrderEmployee: employee_id=$oe->employee_id, card_design_type=$oe->card_design_type, card_design_number=$oe->card_design_number, no_design_yet=" . ($oe->no_design_yet ? 'true' : 'false') . "\n";
        }
    }
}
if (!$found) {
    echo "   Aucune commande trouvée.\n";
}

