<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\ExpenseRepository;
use App\Support\Request;
use App\Support\Response;
use App\Validation\ExpenseValidator;

/**
 * v1 (legacy) single-table expense endpoints. Superseded by the account /
 * transaction model under /api/v1; kept mounted until the frontend moves over.
 */
final class ExpenseController
{
    public function __construct(private readonly ExpenseRepository $expenses)
    {
    }

    public function index(Request $request, array $user): Response
    {
        $filters = [
            'category' => $request->query('category'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $expenses = $this->expenses->allForUser((int) $user['id'], $filters);

        return Response::json(['data' => array_map([$this, 'format'], $expenses)]);
    }

    public function store(Request $request, array $user): Response
    {
        $data = ExpenseValidator::validate($request->all());
        $expense = $this->expenses->create((int) $user['id'], $data);

        return Response::json(['data' => $this->format($expense)], 201);
    }

    public function update(Request $request, array $user, string $id): Response
    {
        if (!ctype_digit($id)) {
            throw new NotFoundException('Expense not found.');
        }

        $data = ExpenseValidator::validate($request->all(), partial: true);

        if ($data === []) {
            throw new ValidationException([], 'No valid fields provided to update.');
        }

        $expense = $this->expenses->update((int) $id, (int) $user['id'], $data);

        if ($expense === null) {
            throw new NotFoundException('Expense not found.');
        }

        return Response::json(['data' => $this->format($expense)]);
    }

    public function destroy(Request $request, array $user, string $id): Response
    {
        if (!ctype_digit($id) || !$this->expenses->delete((int) $id, (int) $user['id'])) {
            throw new NotFoundException('Expense not found.');
        }

        return Response::json(['message' => 'Expense deleted.']);
    }

    public function summary(Request $request, array $user): Response
    {
        $summary = $this->expenses->summaryByCategory((int) $user['id']);
        $total = round(array_sum(array_column($summary, 'total')), 2);

        return Response::json(['data' => $summary, 'total' => $total]);
    }

    private function format(array $expense): array
    {
        return [
            'id' => (int) $expense['id'],
            'amount' => (float) $expense['amount'],
            'category' => $expense['category'],
            'description' => $expense['description'],
            'expense_date' => $expense['expense_date'],
            'created_at' => $expense['created_at'],
            'updated_at' => $expense['updated_at'],
        ];
    }
}
