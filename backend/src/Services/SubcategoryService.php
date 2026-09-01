<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SubcategoryRepository;

final class SubcategoryService
{
    public function __construct(private readonly SubcategoryRepository $subcategories)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(?int $categoryId = null): array
    {
        return array_map([$this, 'present'], $this->subcategories->all($categoryId));
    }

    /**
     * Resolves a subcategory id for use on a transaction, checking that it
     * exists and belongs to the category the transaction is filed under.
     *
     * @return array<string, mixed>
     */
    public function requireValid(int $subcategoryId, int $categoryId): array
    {
        $subcategory = $this->subcategories->find($subcategoryId);

        if ($subcategory === null) {
            throw NotFoundException::for('Subcategory');
        }

        if ((int) $subcategory['category_id'] !== $categoryId) {
            throw new ValidationException([
                'subcategory_id' => 'That subcategory does not belong to the selected category.',
            ]);
        }

        return $subcategory;
    }

    /** @param array<string, mixed> $subcategory */
    public function present(array $subcategory): array
    {
        return [
            'id' => (int) $subcategory['id'],
            'category_id' => (int) $subcategory['category_id'],
            'name' => $subcategory['name'],
            'created_at' => $subcategory['created_at'],
        ];
    }
}
