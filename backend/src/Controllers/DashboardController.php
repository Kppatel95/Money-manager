<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use App\Support\Request;
use App\Support\Response;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function summary(Request $request, int $userId): Response
    {
        $month = $request->query('month');

        return Response::data($this->dashboard->summary($userId, is_string($month) ? $month : null));
    }
}
