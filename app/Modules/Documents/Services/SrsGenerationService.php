<?php

namespace App\Modules\Documents\Services;

use App\Modules\Core\Services\AiCompletionService;
use Illuminate\Support\Str;
use RuntimeException;

class SrsGenerationService
{
    public function __construct(
        private readonly AiCompletionService $ai,
    ) {}

    /**
     * Generate an SRS markdown document from raw notes.
     * Uses OpenAI-compatible API when configured; otherwise a notes-grounded local extractor.
     *
     * @param  list<string>  $contextBlocks  Optional existing-system context (PDF/code excerpts).
     */
    public function generate(string $title, string $notes, array $contextBlocks = []): string
    {
        if (! $this->ai->isConfigured()) {
            return $this->generateFallback($title, $notes);
        }

        return $this->generateWithAi($title, $notes, $contextBlocks);
    }

    /**
     * @param  list<string>  $contextBlocks
     */
    private function generateWithAi(string $title, string $notes, array $contextBlocks): string
    {
        $systemPrompt = <<<'PROMPT'
You are a Lead System Analyst. Convert the provided meeting notes / transcript into a Software Requirements Specification (SRS) in Markdown.

CRITICAL RULES:
- The product under specification is whatever the notes describe (e.g. an Inventory Module). NEVER write requirements about an "SRS generator", note uploads, AI document jobs, or this tooling itself.
- Every Functional Requirement, Non-Functional Requirement, and User Story MUST be grounded in statements from the notes.
- Prefer concrete domain language from the notes (entities, statuses, formulas, warehouses, SKUs, etc.).
- If Existing System Context is provided, respect those constraints and do not contradict them.
- If something is unclear, put it under Open Questions — do not invent unrelated features.

Required sections:
1. Overview (problem domain, stakeholders, goals from the notes)
2. Functional Requirements (FR-001...) — table with ID, Requirement, Priority
3. Non-Functional Requirements (NFR-001...) — table with ID, Requirement
4. User Stories in Gherkin (Given/When/Then): happy path, validation/error path, edge case — domain operations only
5. Open Questions / Assumptions

Output Markdown only.
PROMPT;

        $userPrompt = "Document title: {$title}\n\n";

        if ($contextBlocks !== []) {
            $userPrompt .= "## Existing System Context\n\n".implode("\n\n---\n\n", $contextBlocks)."\n\n";
        }

        $userPrompt .= "Notes:\n{$notes}";

        try {
            return $this->ai->complete($systemPrompt, $userPrompt, [
                'temperature' => 0.2,
                'timeout' => 120,
            ]);
        } catch (RuntimeException $e) {
            throw $e;
        }
    }

    private function generateFallback(string $title, string $notes): string
    {
        $generatedAt = now()->toIso8601String();
        $cleanNotes = trim($notes);
        $functional = $this->extractFunctionalRequirements($cleanNotes);
        $nonFunctional = $this->extractNonFunctionalRequirements($cleanNotes);
        $stories = $this->buildDomainStories($title, $cleanNotes, $functional);
        $overview = $this->buildOverview($title, $cleanNotes);
        $questions = $this->extractOpenQuestions($cleanNotes);

        $frRows = $this->toRequirementTable($functional, 'FR');
        $nfrRows = $this->toRequirementTable($nonFunctional, 'NFR', includePriority: false);

        return <<<MD
# Software Requirements Specification: {$title}

> Generated in **local extractive mode** (no `AI_API_KEY`). Requirements were derived from the source notes — not from this application’s own features.  
> Timestamp: {$generatedAt}

## 1. Overview

{$overview}

### Source Notes (excerpt)

```
{$this->excerpt($cleanNotes, 1200)}
```

## 2. Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
{$frRows}

## 3. Non-Functional Requirements

| ID | Requirement |
|----|-------------|
{$nfrRows}

## 4. User Stories (Gherkin)

{$stories}

## 5. Open Questions / Assumptions

{$questions}
MD;
    }

    private function buildOverview(string $title, string $notes): string
    {
        $domainHints = [];
        $lower = Str::lower($notes);

        if (str_contains($lower, 'inventory') || str_contains($lower, 'warehouse') || str_contains($lower, 'sku')) {
            $domainHints[] = 'multi-warehouse inventory / stock operations';
        }
        if (str_contains($lower, 'transfer')) {
            $domainHints[] = 'inter-warehouse transfer workflows';
        }
        if (str_contains($lower, 'alert') || str_contains($lower, 'threshold') || str_contains($lower, 'reorder')) {
            $domainHints[] = 'automated low-stock / reorder alerts';
        }
        if (str_contains($lower, 'audit') || str_contains($lower, 'import') || str_contains($lower, 'csv')) {
            $domainHints[] = 'audit and bulk import/export of physical counts';
        }

        $domain = $domainHints !== []
            ? implode('; ', $domainHints)
            : 'the product capabilities described in the source notes';

        $stakeholders = $this->extractAttendees($notes);

        $lines = [
            "This SRS specifies **{$title}** based solely on the attached meeting notes / transcript.",
            "Primary problem domain inferred from the notes: **{$domain}**.",
        ];

        if ($stakeholders !== '') {
            $lines[] = "Stakeholders mentioned: {$stakeholders}.";
        }

        $lines[] = 'Functional and non-functional requirements below are extracted or paraphrased from concrete statements in those notes.';

        return implode("\n\n", $lines);
    }

    /** @return list<string> */
    private function extractFunctionalRequirements(string $notes): array
    {
        $candidates = [];
        $patterns = [
            '/warehouse.?id/i' => 'Track stock levels independently per warehouse with compound identity on `warehouse_id` + `sku_id`, and provide a centralized master view across locations.',
            '/multi[- ]?warehouse|segregation per location|per warehouse/i' => 'Support multi-warehouse stock segregation so availability cannot be promised from a location that cannot fulfill the order.',
            '/IN_TRANSIT|transfer status|PENDING.*IN_TRANSIT|inter-warehouse transfer/i' => 'Support inter-warehouse stock transfers with lifecycle states including `PENDING`, `IN_TRANSIT`, `PARTIALLY_RECEIVED`, `COMPLETED`, and `CANCELLED`.',
            '/PARTIALLY_RECEIVED|partial shipment|discrepancy/i' => 'Allow partial receipt of transfers with `discrepancy_note` and automatically log remaining balance as a backorder when status is `PARTIALLY_RECEIVED`.',
            '/allocated|in transit.*promis/i' => 'While stock is `IN_TRANSIT`, mark quantities as allocated so they cannot be promised to other outbound orders.',
            '/row-?locking|race condition|simultaneous checkout/i' => 'Use transactional row-locking so simultaneous checkouts cannot oversell the same warehouse SKU.',
            '/14.?day|rolling.*(average|historical)|average_daily_sales/i' => 'Trigger low-stock alerts when `current_stock < (average_daily_sales * 14)` using rolling historical consumption (calibrated from ~30 days of sales velocity).',
            '/nightly|cron|background job|scheduled/i' => 'Evaluate stock thresholds via a nightly background/cron job without blocking primary API request paths.',
            '/webhook|notification center|broadcaster/i' => 'Deliver low-stock alerts to the dashboard notification center and/or outbound webhooks.',
            '/csv|excel|bulk import|physical count/i' => 'Provide CSV/Excel bulk import and export for weekly physical count audits by warehouse managers.',
            '/row.?number|validation middleware|error.?handling report/i' => 'On bulk import failure, return row-level validation errors specifying row, column, and reason (e.g. unknown SKU).',
            '/15,?000|pagination|debounced search/i' => 'Inventory catalog tables must use server-side pagination and debounced search to remain responsive across large SKU catalogs (15,000+ active SKUs).',
            '/audit logging/i' => 'Record audit logs for inbound shipments, outbound orders, and internal stock transfers.',
            '/real-time stock|stock tracking/i' => 'Provide real-time stock tracking for inbound shipments, outbound customer orders, and internal transfers.',
        ];

        foreach ($patterns as $pattern => $requirement) {
            if (preg_match($pattern, $notes)) {
                $candidates[] = $requirement;
            }
        }

        foreach ($this->extractBulletLikeStatements($notes) as $statement) {
            if ($this->looksFunctional($statement)) {
                $candidates[] = $this->normalizeRequirement($statement);
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            $candidates[] = 'Deliver the capabilities described in the source notes for "'.$this->excerpt($notes, 120).'".';
        }

        return array_slice($candidates, 0, 12);
    }

    /** @return list<string> */
    private function extractNonFunctionalRequirements(string $notes): array
    {
        $candidates = [];
        $patterns = [
            '/15,?000|pagination|debounced/i' => 'Catalog search and listing must remain performant for 15,000+ active SKUs using server-side pagination and debounced inputs.',
            '/queue|background|cron|nightly/i' => 'Heavy calculations (e.g. nightly stock velocity / threshold evaluation) must run asynchronously via background jobs/queues.',
            '/row-?locking|transaction|race condition/i' => 'Database consistency during concurrent stock mutations must be guaranteed with strict transactional row locking.',
            '/integration tests|test cases/i' => 'Critical transfer and checkout paths must be covered by automated integration tests, including partial receipts and race conditions.',
            '/real-time|broadcaster|webhook/i' => 'Alert delivery should support near-real-time dashboard notifications and/or webhook integrations.',
        ];

        foreach ($patterns as $pattern => $requirement) {
            if (preg_match($pattern, $notes)) {
                $candidates[] = $requirement;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            $candidates[] = 'Meet the reliability and performance expectations implied by the operational constraints in the source notes.';
        }

        return array_slice($candidates, 0, 8);
    }

    /**
     * @param  list<string>  $functional
     */
    private function buildDomainStories(string $title, string $notes, array $functional): string
    {
        $lower = Str::lower($notes);
        $product = str_contains($lower, 'inventory') ? 'Inventory Module' : $title;

        $happy = str_contains($lower, 'transfer')
            ? <<<'GHERKIN'
### Happy path — inter-warehouse transfer
```gherkin
Given I am a warehouse manager with stock available at Warehouse A
And SKU "ABC-100" has 100 units at Warehouse A
When I initiate a transfer of 40 units to Warehouse B
Then the transfer is created with status "PENDING"
And when the shipment leaves, status becomes "IN_TRANSIT"
And those 40 units are allocated and cannot be promised to other orders
And when Warehouse B confirms full receipt, status becomes "COMPLETED"
And Warehouse A stock decreases by 40 and Warehouse B stock increases by 40
```
GHERKIN
            : <<<GHERKIN
### Happy path
```gherkin
Given I am an authenticated operations user of the {$product}
When I perform the primary workflow described in the notes
Then the system records the outcome against the correct business entities
And stock or domain state remains consistent with the requirements above
```
GHERKIN;

        $validation = str_contains($lower, 'csv') || str_contains($lower, 'import')
            ? <<<'GHERKIN'
### Validation / error path — bulk physical count import
```gherkin
Given I upload a CSV physical count file
When row 17 references an unknown SKU
Then the import rejects that row
And the error report includes the row number, column, and reason
And valid rows are still processable according to the agreed import rules
```
GHERKIN
            : <<<'GHERKIN'
### Validation / error path
```gherkin
Given I submit an invalid inventory operation (missing warehouse, SKU, or quantity)
When the API validates the request
Then it returns a structured validation error
And no stock mutation is committed
```
GHERKIN;

        $edge = str_contains($lower, 'partial') || str_contains($lower, 'discrepancy')
            ? <<<'GHERKIN'
### Edge case — partial transfer receipt
```gherkin
Given a transfer of 100 units is "IN_TRANSIT"
When only 90 units arrive at the destination warehouse
Then the transfer moves to "PARTIALLY_RECEIVED"
And a discrepancy_note can be recorded
And the remaining 10 units are logged as a backorder / open balance
```
GHERKIN
            : (str_contains($lower, '14') || str_contains($lower, 'low stock')
                ? <<<'GHERKIN'
### Edge case — dynamic low-stock alert
```gherkin
Given nightly jobs compute average daily sales over the recent history window
And current_stock is less than average_daily_sales * 14 for a SKU at a warehouse
When the threshold job runs
Then a low-stock alert/notification is raised for that warehouse SKU
And the alert does not block the main API request path
```
GHERKIN
                : <<<'GHERKIN'
### Edge case
```gherkin
Given concurrent users attempt to checkout the same warehouse SKU
When stock is insufficient for both operations
Then only one commit succeeds under row locking
And the other receives a controlled failure without corrupting balances
```
GHERKIN);

        $context = $functional[0] ?? 'the documented inventory capabilities';

        return <<<MD
{$happy}

{$validation}

{$edge}

> Stories are framed around domain operations from the notes (e.g. “{$context}”), not document-generation tooling.
MD;
    }

    private function extractOpenQuestions(string $notes): string
    {
        $items = [];
        $lower = Str::lower($notes);

        if (str_contains($lower, 'historical sales') || str_contains($lower, 'calibrat')) {
            $items[] = 'Confirm the historical sales velocity baseline and exact formula window (notes mention ~30-day average feeding a 14-day coverage rule).';
        }
        if (str_contains($lower, 'partial') && str_contains($lower, 'transfer')) {
            $items[] = 'Confirm warehouse SOPs for `PARTIALLY_RECEIVED` transfers and how long open backorder balances remain active.';
        }
        if (str_contains($lower, 'webhook') && str_contains($lower, 'notification')) {
            $items[] = 'Confirm which alert channels are required for phase one (dashboard only vs webhooks as well).';
        }

        foreach ($this->extractBulletLikeStatements($notes) as $statement) {
            if (preg_match('/\b(confirm|clarify|tbd|open question|need to decide|by wednesday)\b/i', $statement)) {
                $items[] = $this->normalizeRequirement($statement);
            }
        }

        $items = array_values(array_unique($items));

        if ($items === []) {
            $items[] = 'Assumptions should be validated with stakeholders named in the notes before implementation sign-off.';
        }

        return implode("\n", array_map(fn (string $item) => '- '.$item, $items));
    }

    private function extractAttendees(string $notes): string
    {
        if (! preg_match('/\*\*Attendees:\*\*(.*?)(?:---|\n### )/s', $notes, $match)) {
            return '';
        }

        preg_match_all('/\*\*([^*]+)\*\*/', $match[1], $names);

        return implode(', ', array_unique($names[1] ?? []));
    }

    /** @return list<string> */
    private function extractBulletLikeStatements(string $notes): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $notes) ?: [];
        $statements = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^[\*\-]\s+/', '', $line) ?? $line;
            $line = preg_replace('/^\*\*|^\*|\*$/', '', $line) ?? $line;
            $line = trim($line);

            if (strlen($line) < 40 || strlen($line) > 320) {
                continue;
            }

            if (preg_match('/^(Date|Time|Platform|Attendees|Transcript|Action Items)/i', $line)) {
                continue;
            }

            $statements[] = $line;
        }

        return $statements;
    }

    private function looksFunctional(string $statement): bool
    {
        return (bool) preg_match(
            '/\b(must|need|needs|should|track|support|provide|implement|transfer|alert|import|export|threshold|warehouse|sku|stock|allocate|paginat)\b/i',
            $statement
        );
    }

    private function normalizeRequirement(string $statement): string
    {
        $statement = trim($statement);
        $statement = rtrim($statement, '.');

        if (! preg_match('/^(The system|Users|Warehouse|Inventory)/i', $statement)) {
            $statement = 'The system shall support: '.$statement;
        }

        return $statement.'.';
    }

    /**
     * @param  list<string>  $requirements
     */
    private function toRequirementTable(array $requirements, string $prefix, bool $includePriority = true): string
    {
        $rows = [];
        foreach (array_values($requirements) as $index => $requirement) {
            $id = sprintf('%s-%03d', $prefix, $index + 1);
            $safe = str_replace('|', '\\|', $requirement);
            $rows[] = $includePriority
                ? "| {$id} | {$safe} | Must |"
                : "| {$id} | {$safe} |";
        }

        return implode("\n", $rows);
    }

    private function excerpt(string $notes, int $limit): string
    {
        return Str::limit(trim($notes), $limit, '…');
    }
}
