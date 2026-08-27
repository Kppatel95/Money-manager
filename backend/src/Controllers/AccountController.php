<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountService;
use App\Support\Request;
use App\Support\Response;

final class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        $includeArchived = in_array($request->query('include_archived'), ['1', 'true'], true);

        return Response::data($this->accounts->list($userId, $includeArchived));
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::created($this->accounts->create($userId, $request->all()));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->accounts->get($userId, $this->id($params)));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->accounts->update($userId, $this->id($params), $request->all()));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->accounts->delete($userId, $this->id($params)));
    }

    /** @param array<string, string> $params */
    public function balance(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->accounts->balance($userId, $this->id($params)));
    }
}
