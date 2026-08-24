<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ExpenseRepository;
use App\Support\Request;
use App\Support\Response;
use App\Validation\ExpenseValidator;
use App\Validation\ValidationException;

final class ExpenseController
{
    public function __construct(private readonly ExpenseRepository $expenses)
    {
    }

    public function index(Request $request, array $user): void
    {
        $filters = [
            'category' => $request->query('category'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $expenses = $this->expenses->allForUser((int) $user['id'], $filters);

        Response::json(['data' => array_map([$this, 'format'], $expenses)]);
    }

    public function store(Request $request, array $user): void
    {
        try {
            $data = ExpenseValidator::validate($request->all());
        } catch (ValidationException $e) {
            Response::error('Validation failed.', 422, $e->errors());
        }

        $expense = $this->expenses->create((int) $user['id'], $data);

        Response::json(['data' => $this->format($expense)], 201);
    }

    public function update(Request $request, array $user, string $id): void
    {
        if (!ctype_digit($id)) {
            Response::error('Expense not found.', 404);
        }

        try {
            $data = ExpenseValidator::validate($request->all(), partial: true);
        } catch (ValidationException $e) {
            Response::error('Validation failed.', 422, $e->errors());
        }

        if ($data === []) {
            Response::error('No valid fields provided to update.', 422);
        }

        $expense = $this->expenses->update((int) $id, (int) $user['id'], $data);

        if ($expense === null) {
            Response::error('Expense not found.', 404);
        }

        Response::json(['data' => $this->format($expense)]);
    }

    public function destroy(Request $request, array $user, string $id): void
    {
        if (!ctype_digit($id) || !$this->expenses->delete((int) $id, (int) $user['id'])) {
            Response::error('Expense not found.', 404);
        }

        Response::json(['message' => 'Expense deleted.']);
    }

    public function summary(Request $request, array $user): void
    {
        $summary = $this->expenses->summaryByCategory((int) $user['id']);
        $total = round(array_sum(array_column($summary, 'total')), 2);

        Response::json(['data' => $summary, 'total' => $total]);
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
