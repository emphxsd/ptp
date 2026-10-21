<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

class AdminController extends Controller
{
    private const USERS_PER_PAGE = 25;

    public function index(): View
    {
        return view('admin.index', [
            'users' => User::with('role')->orderBy('name')->orderBy('id')->paginate(self::USERS_PER_PAGE),
        ]);
    }
}
