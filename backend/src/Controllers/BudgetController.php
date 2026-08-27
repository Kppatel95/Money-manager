<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BudgetService;
use App\Support\Request;
use App\Support\Response;

final class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        $month = $request->query('month');
        $result = $this->budgets->listForMonth($userId, is_string($month) ? $month : null);

        return Response::data($result['data'], 200, $result['meta']);
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::created($this->budgets->create($userId, $request->all()));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->budgets->update($userId, $this->id($params), $request->all()));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, int $userId, array $params): Response
    {
        $this->budgets->delete($userId, $this->id($params));

        return Response::noContent();
    }
}
