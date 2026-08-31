<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SubcategoryRepository;
use App\Services\SubcategoryService;
use Tests\Support\ServiceTestCase;

final class SubcategoryServiceTest extends ServiceTestCase
{
    private SubcategoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubcategoryService(new SubcategoryRepository($this->pdo));
    }

    public function testListsTheSeededDefaults(): void
    {
        $names = array_column($this->service->list(), 'name');

        $this->assertContains('Groceries', $names);
        $this->assertContains('Family Dinner', $names);
    }

    public function testFiltersByCategory(): void
    {
        $foodId = $this->systemCategoryId('Food');
        $transportId = $this->systemCategoryId('Transport');

        $foodSubcategories = $this->service->list($foodId);

        $this->assertNotEmpty($foodSubcategories);
        $this->assertSame([$foodId], array_values(array_unique(array_column($foodSubcategories, 'category_id'))));
        $this->assertNotContains($transportId, array_column($foodSubcategories, 'category_id'));
    }

    public function testRequireValidAcceptsAMatchingCategory(): void
    {
        $foodId = $this->systemCategoryId('Food');
        $groceriesId = $this->subcategoryId('Food', 'Groceries');

        $subcategory = $this->service->requireValid($groceriesId, $foodId);

        $this->assertSame('Groceries', $subcategory['name']);
    }

    public function testRequireValidRejectsAMismatchedCategory(): void
    {
        $transportId = $this->systemCategoryId('Transport');
        $groceriesId = $this->subcategoryId('Food', 'Groceries');

        $this->expectException(ValidationException::class);
        $this->service->requireValid($groceriesId, $transportId);
    }

    public function testRequireValidRejectsAnUnknownId(): void
    {
        $foodId = $this->systemCategoryId('Food');

        $this->expectException(NotFoundException::class);
        $this->service->requireValid(999999, $foodId);
    }
}
