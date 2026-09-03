<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CategoryRepository;
use App\Repositories\SubcategoryRepository;
use App\Services\BillScanService;
use App\Services\CategoryService;
use App\Services\SubcategoryService;
use App\Support\AnthropicClient;
use App\Support\Logger;
use Tests\Support\ServiceTestCase;

/**
 * Exercises BillScanService::presentDraft() -- the pure mapping from a fake
 * Anthropic tool response into a draft transaction -- without making a real
 * network call. The AnthropicClient here is never invoked.
 */
final class BillScanServiceTest extends ServiceTestCase
{
    private BillScanService $service;
    private int $userId;
    /** @var array<int, array<string, mixed>> */
    private array $categoryById;
    /** @var array<int, array<string, mixed>> */
    private array $subcategoryById;

    protected function setUp(): void
    {
        parent::setUp();

        $categories = new CategoryService(new CategoryRepository($this->pdo));
        $subcategories = new SubcategoryService(new SubcategoryRepository($this->pdo));
        $this->service = new BillScanService(
            $categories,
            $subcategories,
            new AnthropicClient(null, 'claude-sonnet-5'),
            Logger::null()
        );

        $this->userId = $this->createUser();

        $this->categoryById = [];
        foreach ($categories->list($this->userId) as $category) {
            $this->categoryById[$category['id']] = $category;
        }

        $this->subcategoryById = [];
        foreach ($subcategories->list() as $subcategory) {
            $this->subcategoryById[$subcategory['id']] = $subcategory;
        }
    }

    public function testAWellFormedBillMapsCleanly(): void
    {
        $foodId = $this->systemCategoryId('Food');
        $groceriesId = $this->subcategoryId('Food', 'Groceries');

        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '42.5',
            'transaction_date' => '2026-03-04',
            'description' => "Trader Joe's",
            'category_id' => $foodId,
            'subcategory_id' => $groceriesId,
            'payment_method' => 'Credit Card',
            'notes' => 'Card ending 1234',
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame('expense', $draft['type']);
        $this->assertSame('42.50', $draft['amount']);
        $this->assertSame('2026-03-04', $draft['transaction_date']);
        $this->assertSame("Trader Joe's", $draft['description']);
        $this->assertSame($foodId, $draft['category_id']);
        $this->assertSame('Food', $draft['category_name']);
        $this->assertSame($groceriesId, $draft['subcategory_id']);
        $this->assertSame('Groceries', $draft['subcategory_name']);
        $this->assertSame('Credit Card', $draft['payment_method']);
        $this->assertSame('Card ending 1234', $draft['notes']);
    }

    public function testAnUnknownCategoryIdIsDroppedRatherThanTrusted(): void
    {
        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '10.00',
            'transaction_date' => '2026-03-04',
            'description' => 'Mystery shop',
            'category_id' => 999999,
        ], $this->categoryById, $this->subcategoryById);

        $this->assertNull($draft['category_id']);
        $this->assertNull($draft['category_name']);
    }

    public function testACategoryOfTheWrongTypeIsDropped(): void
    {
        // Salary is an income category; this bill is an expense.
        $salaryId = $this->systemCategoryId('Salary');

        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '10.00',
            'transaction_date' => '2026-03-04',
            'description' => 'Mismatched category',
            'category_id' => $salaryId,
        ], $this->categoryById, $this->subcategoryById);

        $this->assertNull($draft['category_id']);
    }

    public function testASubcategoryBelongingToADifferentCategoryIsDropped(): void
    {
        $foodId = $this->systemCategoryId('Food');
        // Fuel belongs to Transport, not Food.
        $fuelId = $this->subcategoryId('Transport', 'Fuel');

        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '10.00',
            'transaction_date' => '2026-03-04',
            'description' => 'Mismatched subcategory',
            'category_id' => $foodId,
            'subcategory_id' => $fuelId,
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame($foodId, $draft['category_id']);
        $this->assertNull($draft['subcategory_id']);
        $this->assertNull($draft['subcategory_name']);
    }

    public function testASubcategoryIsDroppedWhenTheCategoryItselfWasDropped(): void
    {
        $groceriesId = $this->subcategoryId('Food', 'Groceries');

        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '10.00',
            'transaction_date' => '2026-03-04',
            'description' => 'No category, but a subcategory was guessed anyway',
            'category_id' => 999999,
            'subcategory_id' => $groceriesId,
        ], $this->categoryById, $this->subcategoryById);

        $this->assertNull($draft['category_id']);
        $this->assertNull($draft['subcategory_id']);
    }

    public function testAnUnparseableAmountFallsBackToZeroRatherThanCrashing(): void
    {
        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => 'not a number',
            'transaction_date' => '2026-03-04',
            'description' => 'Illegible receipt',
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame('0.00', $draft['amount']);
    }

    public function testAnInvalidDateFallsBackToToday(): void
    {
        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '5.00',
            'transaction_date' => 'not-a-date',
            'description' => 'Faded receipt',
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame(date('Y-m-d'), $draft['transaction_date']);
    }

    public function testAMissingDescriptionFallsBackToAPlaceholder(): void
    {
        $draft = $this->service->presentDraft([
            'type' => 'expense',
            'amount' => '5.00',
            'transaction_date' => '2026-03-04',
            'description' => '',
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame('Scanned bill', $draft['description']);
    }

    public function testAnUnrecognisedTypeDefaultsToExpense(): void
    {
        $draft = $this->service->presentDraft([
            'type' => 'transfer',
            'amount' => '5.00',
            'transaction_date' => '2026-03-04',
            'description' => 'Odd type',
        ], $this->categoryById, $this->subcategoryById);

        $this->assertSame('expense', $draft['type']);
    }

    public function testExtractBillsAcceptsANativeArray(): void
    {
        $bills = $this->service->extractBills(['bills' => [['description' => 'A']]]);

        $this->assertSame([['description' => 'A']], $bills);
    }

    /**
     * Observed live: the model sometimes returns the "bills" property as a
     * JSON-encoded string containing the array directly, instead of a native
     * nested array.
     */
    public function testExtractBillsUnwrapsAJsonEncodedArrayString(): void
    {
        $bills = $this->service->extractBills(['bills' => '[{"description":"A"}]']);

        $this->assertSame([['description' => 'A']], $bills);
    }

    /**
     * Also observed live: the model re-serialises the whole {"bills": [...]}
     * object into the "bills" string property.
     */
    public function testExtractBillsUnwrapsAJsonEncodedWrapperObjectString(): void
    {
        $bills = $this->service->extractBills(['bills' => '{"bills": [{"description":"A"}]}']);

        $this->assertSame([['description' => 'A']], $bills);
    }

    public function testExtractBillsReturnsEmptyForGarbage(): void
    {
        $this->assertSame([], $this->service->extractBills(['bills' => 'not json']));
        $this->assertSame([], $this->service->extractBills(['bills' => null]));
        $this->assertSame([], $this->service->extractBills([]));
    }
}
