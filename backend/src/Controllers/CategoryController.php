<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CategoryService;
use App\Support\Request;
use App\Support\Response;

final class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        $type = $request->query('type');

        return Response::data($this->categories->list($userId, is_string($type) ? $type : null));
    }

    public function store(Request $request, int $userId): Response
    {
        return Response::created($this->categories->create($userId, $request->all()));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, int $userId, array $params): Response
    {
        return Response::data($this->categories->update($userId, $this->id($params), $request->all()));
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, int $userId, array $params): Response
    {
        $this->categories->delete($userId, $this->id($params));

        return Response::noContent();
    }
}
