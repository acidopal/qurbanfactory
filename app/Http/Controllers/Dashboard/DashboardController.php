<?php

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\BusinessLayer\DashboardBusinessLayer;

class DashboardController extends Controller
{
    private $dashboardBusinessLayer;

    public function __construct()
    {
        $this->dashboardBusinessLayer = new DashboardBusinessLayer();
    }

    public function index()
    {
        $params = [
            'title' => 'Dashboard'
        ];

        return view('admin.dashboard.index', $params);
    }
   
}
