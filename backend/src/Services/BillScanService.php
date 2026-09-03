<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UpstreamServiceException;
use App\Exceptions\ValidationException;
use App\Support\AnthropicClient;
use App\Support\Logger;
use App\Support\Money;
use App\Validation\Validator;
use Throwable;

/**
 * Reads a photographed/scanned bill (image or PDF) and turns it into one or
 * more draft transactions for the user to review before anything is saved.
 *
 * Nothing here writes to the database -- this only ever returns drafts.
 * Persisting them reuses the normal transaction-creation path
 * (TransactionService::create), one call per draft, so the same validation
 * and account-balance rules apply whether a transaction came from a form or
 * from a scan.
 */
final class BillScanService
{
    private const MAX_BYTES = 15 * 1024 * 1024; // 15MB, comfortably under the Anthropic API's 32MB request cap.

    private const MAX_BILLS_PER_SCAN = 25;

    /** Supported upload mimes => the Anthropic content-block type each becomes. */
    private const ALLOWED_MEDIA_TYPES = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/webp' => 'image',
        'application/pdf' => 'document',
    ];

    public function __construct(
        private readonly CategoryService $categories,
        private readonly SubcategoryService $subcategories,
        private readonly AnthropicClient $ai,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed>|null $file the raw $_FILES-shaped entry for the uploaded field
     * @return array<int, array<string, mixed>> draft transactions, newest/first-found first
     */
    public function scan(int $userId, ?array $file): array
    {
        [$mediaType, $bytes] = $this->readUpload($file);

        $categories = $this->categories->list($userId);
        $categoryById = [];
        foreach ($categories as $category) {
            $categoryById[$category['id']] = $category;
        }

        $subcategories = $this->subcategories->list();
        $subcategoryById = [];
        foreach ($subcategories as $subcategory) {
            $subcategoryById[$subcategory['id']] = $subcategory;
        }

        $content = [
            [
                'type' => self::ALLOWED_MEDIA_TYPES[$mediaType],
                'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode($bytes)],
            ],
            ['type' => 'text', 'text' => $this->prompt($categories, $subcategories)],
        ];

        try {
            $result = $this->ai->callTool($content, $this->tool());
        } catch (Throwable $e) {
            $notConfigured = str_contains($e->getMessage(), 'not configured');
            $this->logger->exception($e, false, ['service' => 'bill-scan', 'user_id' => $userId]);

            throw new UpstreamServiceException($notConfigured
                ? 'Bill scanning is not set up on this server yet -- ask an admin to add an Anthropic API key.'
                : 'Could not read this file right now. Try again in a moment, or enter the transaction manually.');
        }

        $bills = $this->extractBills($result);

        $drafts = [];
        foreach (array_slice($bills, 0, self::MAX_BILLS_PER_SCAN) as $bill) {
            if (is_array($bill)) {
                $drafts[] = $this->presentDraft($bill, $categoryById, $subcategoryById);
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{0: string, 1: string} media type, raw bytes
     */
    private function readUpload(?array $file): array
    {
        if ($file === null || !isset($file['error'])) {
            throw new ValidationException(['file' => 'Choose a receipt image or PDF to scan.']);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => $this->uploadErrorMessage((int) $file['error'])]);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new ValidationException(['file' => 'The upload could not be read.']);
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'Files must be 15MB or smaller.']);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo !== false ? finfo_file($finfo, $tmpPath) : false;
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        $mediaType = is_string($detected) ? $detected : null;

        if ($mediaType === null || !array_key_exists($mediaType, self::ALLOWED_MEDIA_TYPES)) {
            throw new ValidationException(['file' => 'Upload a JPEG, PNG, WEBP image or a PDF.']);
        }

        $bytes = file_get_contents($tmpPath);

        if ($bytes === false) {
            throw new ValidationException(['file' => 'The upload could not be read.']);
        }

        return [$mediaType, $bytes];
    }

    /**
     * The model is asked for `{"bills": [...]}` but has been observed to
     * instead hand back the "bills" property as a JSON-encoded string (either
     * the array itself, or the whole `{"bills": [...]}"` object again,
     * re-serialised) rather than a native nested array. Unwrap either shape
     * rather than trusting the tool schema was followed literally.
     *
     * Public for the same reason as presentDraft(): testable against a fake
     * tool response without a real API call.
     *
     * @param array<string, mixed> $result
     * @return array<int, mixed>
     */
    public function extractBills(array $result): array
    {
        $bills = $result['bills'] ?? null;

        if (is_string($bills)) {
            $decoded = json_decode($bills, true);
            $bills = is_array($decoded) ? ($decoded['bills'] ?? $decoded) : null;
        }

        return is_array($bills) ? $bills : [];
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a receipt image or PDF to scan.',
            default => 'The upload failed. Try again.',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @param array<int, array<string, mixed>> $subcategories
     */
    private function prompt(array $categories, array $subcategories): string
    {
        $categoryLines = array_map(
            static fn (array $c): string => "- id={$c['id']}, type={$c['type']}, name=\"{$c['name']}\"",
            $categories
        );

        $subcategoryLines = array_map(
            static fn (array $s): string => "- id={$s['id']}, category_id={$s['category_id']}, name=\"{$s['name']}\"",
            $subcategories
        );

        return "This file is one or more bills, receipts, or a statement covering several purchases, "
            . "photographed or exported by the account holder. Today's date is " . date('Y-m-d') . ".\n\n"
            . "Identify every distinct bill/receipt/line-item in the file and record each one separately "
            . "using the record_bills tool. For each one:\n"
            . "- amount: the total charged, as a plain number string like \"42.50\".\n"
            . "- transaction_date: the date on the bill in YYYY-MM-DD format. If no date is legible, use "
            . "today's date.\n"
            . "- description: a short merchant/payee name.\n"
            . "- category_id: pick the single best match from the categories list below, by id. Use null if "
            . "nothing fits well -- never invent an id.\n"
            . "- subcategory_id: pick the single best match from the subcategories list below, by id -- but "
            . "ONLY one whose category_id equals the category_id you chose above. Use null if nothing fits, "
            . "or if you used a null category_id -- never invent an id or pick one from the wrong category.\n"
            . "- payment_method: how it was paid, e.g. \"Cash\", \"Credit Card\", \"Debit Card\", \"UPI\", "
            . "\"Bank Transfer\", \"Cheque\", or null if it is not stated.\n"
            . "- type: \"expense\" for a purchase, \"income\" only for a refund/credit.\n\n"
            . "Categories (id, type, name):\n" . implode("\n", $categoryLines) . "\n\n"
            . "Subcategories (id, category_id, name):\n" . implode("\n", $subcategoryLines) . "\n\n"
            . "If the file contains nothing that looks like a bill or receipt, call the tool with an empty "
            . "bills array rather than guessing.";
    }

    /** @return array<string, mixed> */
    private function tool(): array
    {
        return [
            'name' => 'record_bills',
            'description' => 'Records each distinct bill, receipt, or statement line item found in the document.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'bills' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['expense', 'income']],
                                'amount' => ['type' => 'string'],
                                'transaction_date' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'category_id' => ['type' => ['integer', 'null']],
                                'subcategory_id' => ['type' => ['integer', 'null']],
                                'payment_method' => ['type' => ['string', 'null']],
                                'notes' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['type', 'amount', 'transaction_date', 'description'],
                        ],
                    ],
                ],
                'required' => ['bills'],
            ],
        ];
    }

    /**
     * Sanitises one model-produced bill into a draft the frontend can render
     * and, unmodified, submit straight to TransactionService::create(). Never
     * trusts the model's category_id without checking it against a category
     * this user actually owns and whose type matches.
     *
     * Public (rather than private) so it can be unit tested against a fake
     * tool response without making a real Anthropic API call.
     *
     * @param array<string, mixed> $bill
     * @param array<int, array<string, mixed>> $categoryById keyed by category id, as returned by CategoryService::list()
     * @param array<int, array<string, mixed>> $subcategoryById keyed by subcategory id, as returned by SubcategoryService::list()
     * @return array<string, mixed>
     */
    public function presentDraft(array $bill, array $categoryById, array $subcategoryById = []): array
    {
        $type = ($bill['type'] ?? null) === 'income' ? 'income' : 'expense';

        $amountCents = Money::toCents($bill['amount'] ?? null);
        $amount = $amountCents !== null && $amountCents > 0 ? Money::format($amountCents) : '0.00';

        $date = is_string($bill['transaction_date'] ?? null) && Validator::isDate($bill['transaction_date'])
            ? $bill['transaction_date']
            : date('Y-m-d');

        $categoryId = $this->resolveId($bill['category_id'] ?? null, $categoryById, static fn (array $c) => $c['type'] === $type);

        // A subcategory only makes sense once its parent category does; an id
        // valid on its own but pointing at a different category is dropped,
        // mirroring SubcategoryService::requireValid()'s rule.
        $subcategoryId = $categoryId === null ? null : $this->resolveId(
            $bill['subcategory_id'] ?? null,
            $subcategoryById,
            static fn (array $s) => (int) $s['category_id'] === $categoryId
        );

        return [
            'type' => $type,
            'amount' => $amount,
            'transaction_date' => $date,
            'description' => $this->cleanString($bill['description'] ?? null, 255) ?? 'Scanned bill',
            'category_id' => $categoryId,
            'category_name' => $categoryId === null ? null : $categoryById[$categoryId]['name'],
            'subcategory_id' => $subcategoryId,
            'subcategory_name' => $subcategoryId === null ? null : $subcategoryById[$subcategoryId]['name'],
            'payment_method' => $this->cleanString($bill['payment_method'] ?? null, 60),
            'notes' => $this->cleanString($bill['notes'] ?? null, 2000),
        ];
    }

    /**
     * Resolves a model-supplied id against a real, known-visible set of rows,
     * rather than trusting it blindly. Shared by the category_id/subcategory_id
     * checks in presentDraft(), which differ only in which extra rule applies.
     *
     * @param array<int, array<string, mixed>> $byId
     * @param callable(array<string, mixed>): bool $isAcceptable
     */
    private function resolveId(mixed $raw, array $byId, callable $isAcceptable): ?int
    {
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            return null;
        }

        $id = (int) $raw;
        $row = $byId[$id] ?? null;

        return $row !== null && $isAcceptable($row) ? $id : null;
    }

    private function cleanString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
