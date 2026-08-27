<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\CategoryRepository;
use App\Validation\Validator;

/**
 * Categories come in two flavours. System categories (user_id IS NULL) are
 * seeded once by a migration and shared by every user: that keeps registration
 * cheap and means a future default can be added in one row instead of
 * backfilling every account. They are read-only, which is a 403 -- the caller
 * can see the row, they just may not change it. Anything belonging to another
 * user is a 404 instead, so the API never confirms that a foreign id exists.
 */
final class CategoryService
{
    public const TYPES = ['income', 'expense'];

    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $userId, ?string $type = null): array
    {
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            $type = null;
        }

        return array_map([$this, 'present'], $this->categories->visibleTo($userId, $type));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $userId, array $payload): array
    {
        $v = new Validator($payload);
        $name = $v->requiredString('name', 60);
        $type = $v->requiredEnum('type', self::TYPES);
        $icon = $v->optionalString('icon', 8) ?? '';
        $color = $v->optionalColor() ?? '#888888';
        $v->validate();

        if ($this->categories->findOwnedByName($userId, $name, $type) !== null) {
            throw new ConflictException('You already have a category with that name.');
        }

        return $this->present($this->categories->create($userId, [
            'name' => $name,
            'type' => $type,
            'icon' => $icon,
            'color' => $color,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $userId, int $categoryId, array $payload): array
    {
        $category = $this->requireOwned($userId, $categoryId);

        $v = new Validator($payload);
        $fields = [];

        if ($v->has('name')) {
            $fields['name'] = $v->requiredString('name', 60);
        }
        if ($v->has('type')) {
            $fields['type'] = $v->requiredEnum('type', self::TYPES);
        }
        if ($v->has('icon')) {
            $fields['icon'] = $v->optionalString('icon', 8) ?? '';
        }
        if ($v->has('color')) {
            $fields['color'] = $v->optionalColor() ?? '#888888';
        }

        $v->validate();

        $name = $fields['name'] ?? $category['name'];
        $type = $fields['type'] ?? $category['type'];

        if ($this->categories->findOwnedByName($userId, $name, $type, $categoryId) !== null) {
            throw new ConflictException('You already have a category with that name.');
        }

        /** @var array<string, mixed> */
        return $this->present($this->categories->update($categoryId, $userId, $fields));
    }

    public function delete(int $userId, int $categoryId): void
    {
        $this->requireOwned($userId, $categoryId);
        $this->categories->delete($categoryId, $userId);
    }

    /**
     * Resolves a category id for use on a transaction or budget, checking both
     * visibility and that its income/expense type matches.
     *
     * @return array<string, mixed>
     */
    public function requireVisible(int $userId, int $categoryId, ?string $expectedType = null): array
    {
        $category = $this->categories->findVisible($categoryId, $userId);

        if ($category === null) {
            throw NotFoundException::for('Category');
        }

        if ($expectedType !== null && $category['type'] !== $expectedType) {
            throw new \App\Exceptions\ValidationException([
                'category_id' => "That category is an {$category['type']} category.",
            ]);
        }

        return $category;
    }

    /** @return array<string, mixed> */
    private function requireOwned(int $userId, int $categoryId): array
    {
        $category = $this->categories->findVisible($categoryId, $userId);

        if ($category === null) {
            throw NotFoundException::for('Category');
        }

        if ($category['user_id'] === null) {
            throw new ForbiddenException('System categories cannot be modified or deleted.');
        }

        return $category;
    }

    /** @param array<string, mixed> $category */
    public function present(array $category): array
    {
        return [
            'id' => (int) $category['id'],
            'name' => $category['name'],
            'type' => $category['type'],
            'icon' => $category['icon'],
            'color' => $category['color'],
            'is_system' => $category['user_id'] === null,
            'created_at' => $category['created_at'],
        ];
    }
}
