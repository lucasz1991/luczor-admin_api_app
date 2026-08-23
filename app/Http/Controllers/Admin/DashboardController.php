<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminDashboardData;
use Illuminate\Http\Request;

class DashboardController extends AdminController
{
    public function __construct(private readonly AdminDashboardData $data) {}

    public function index(Request $request)
    {
        return view('dashboard.index', $this->data->dashboard($request));
    }

    public function page(Request $request, string $page)
    {
        $this->ensureAdmin($request);
        abort_unless(in_array($page, ['overview', 'providers', 'models', 'telemetry', 'optimizer', 'experiments', 'workflows', 'agents', 'devices', 'api-keys', 'archives', 'settings'], true), 404);

        return view('admin.page', $this->data->forPage($page));
    }
}
