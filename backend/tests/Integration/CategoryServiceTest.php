<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\CategoryRepository;
use App\Services\CategoryService;
use Tests\Support\ServiceTestCase;

final class CategoryServiceTest extends ServiceTestCase
{
    private CategoryService $service;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CategoryService(new CategoryRepository($this->pdo));
        $this->userId = $this->createUser();
    }

    public function testEveryUserSeesTheSystemDefaults(): void
    {
        $names = array_column($this->service->list($this->userId), 'name');

        $this->assertContains('Salary', $names);
        $this->assertContains('Food', $names);
        $this->assertTrue($this->service->list($this->userId)[0]['is_system']);
    }

    public function testFiltersByType(): void
    {
        $income = $this->service->list($this->userId, 'income');

        $this->assertNotEmpty($income);
        $this->assertSame(['income'], array_values(array_unique(array_column($income, 'type'))));
    }

    public function testCreatesAUserOwnedCategory(): void
    {
        $category = $this->service->create($this->userId, [
            'name' => 'Coffee',
            'type' => 'expense',
            'icon' => 'coffee',
            'color' => '#AA3311',
        ]);

        $this->assertFalse($category['is_system']);
        $this->assertSame('#aa3311', $category['color']);
    }

    public function testRejectsADuplicateNameOfTheSameType(): void
    {
        $this->service->create($this->userId, ['name' => 'Coffee', 'type' => 'expense']);

        $this->expectException(ConflictException::class);
        $this->service->create($this->userId, ['name' => 'coffee', 'type' => 'expense']);
    }

    public function testSystemCategoriesCannotBeEdited(): void
    {
        $systemId = $this->systemCategoryId('Food');

        $this->expectException(ForbiddenException::class);
        $this->service->update($this->userId, $systemId, ['name' => 'Groceries']);
    }

    public function testSystemCategoriesCannotBeDeleted(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service->delete($this->userId, $this->systemCategoryId('Food'));
    }

    public function testUserCategoriesCanBeRenamedAndDeleted(): void
    {
        $category = $this->service->create($this->userId, ['name' => 'Coffee', 'type' => 'expense']);

        $renamed = $this->service->update($this->userId, $category['id'], ['name' => 'Cafes']);
        $this->assertSame('Cafes', $renamed['name']);

        $this->service->delete($this->userId, $category['id']);
        $this->assertNotContains('Cafes', array_column($this->service->list($this->userId), 'name'));
    }

    public function testAnotherUsersCategoryIsReportedAsMissing(): void
    {
        $other = $this->createUser('other@example.test');
        $theirs = $this->service->create($other, ['name' => 'Secret', 'type' => 'expense']);

        $this->expectException(NotFoundException::class);
        $this->service->update($this->userId, $theirs['id'], ['name' => 'Mine now']);
    }

    public function testRequireVisibleRejectsAMismatchedType(): void
    {
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->requireVisible($this->userId, $this->systemCategoryId('Salary'), 'expense');
    }
}
