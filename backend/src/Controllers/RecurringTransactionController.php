<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RecurringTransactionService;
use App\Support\Request;
use App\Support\Response;

final class RecurringTransactionController extends Controller
{
    public function __construct(private readonly RecurringTransactionService $recurring)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        return Response::data($this->recurring->list($userId));
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::created($this->recurring->create($userId, $request->all()));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->recurring->update($userId, $this->id($params), $request->all()));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, int $userId, array $params): Response
    {
        $this->recurring->delete($userId, $this->id($params));

        return Response::noContent();
    }
}
