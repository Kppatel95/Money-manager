<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BillScanService;
use App\Support\Request;
use App\Support\Response;

final class BillScanController extends Controller
{
    public function __construct(private readonly BillScanService $billScans)
    {
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::data($this->billScans->scan($userId, $request->file('file')));
    }
}
