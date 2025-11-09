<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;

class NotificationController extends Controller
{
    /**
     * Retourne les dernières notifications pour le super admin.
     */
    public function index()
    {
        $notifications = AdminNotification::orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        return response()->json($notifications);
    }
}


