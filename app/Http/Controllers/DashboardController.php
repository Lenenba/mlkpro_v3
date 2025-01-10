<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {

        $user = Auth::user();

        $users = User::all()->except($user->id);
        // Pass data to Inertia view
        return Inertia::render('Dashboard', [
            'users' => $users,
            'user' => $user
        ]);
    }
}
