<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TransactionService;
use App\Support\Request;
use App\Support\Response;

final class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        $result = $this->transactions->list(
            $userId,
            TransactionService::filtersFromRequest($request),
            $request->queryInt('page', 1) ?? 1,
            $request->queryInt('per_page', TransactionService::DEFAULT_PER_PAGE) ?? TransactionService::DEFAULT_PER_PAGE
        );

        return Response::data($result['data'], 200, $result['meta']);
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::created($this->transactions->create($userId, $request->all()));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->transactions->get($userId, $this->id($params)));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->transactions->update($userId, $this->id($params), $request->all()));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, int $userId, array $params): Response
    {
        $this->transactions->delete($userId, $this->id($params));

        return Response::noContent();
    }
}
