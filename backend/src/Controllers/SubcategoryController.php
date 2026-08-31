<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SubcategoryService;
use App\Support\Request;
use App\Support\Response;

final class SubcategoryController extends Controller
{
    public function __construct(private readonly SubcategoryService $subcategories)
    {
    }

    public function index(Request $request, int $userId): Response
    {
        return Response::data($this->subcategories->list($request->queryInt('category_id')));
    }
}
