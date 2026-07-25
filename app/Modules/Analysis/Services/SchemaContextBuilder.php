<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Support\InventorySchemaBlueprint;
use App\Modules\Documents\Models\SrsDocument;
use App\Modules\Projects\Models\Requirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SchemaContextBuilder
{
    /** @return array{ddl_sql: string, openapi_json: string} */
    public function fallbackForDocument(SrsDocument $document): array
    {
        if ($this->isInventoryDomain($document->title, (string) $document->generated_srs)) {
            return [
                'ddl_sql' => InventorySchemaBlueprint::ddl(),
                'openapi_json' => InventorySchemaBlueprint::openapiJson(),
            ];
        }

        return $this->genericFallback($document->title);
    }

    public function buildSystemPrompt(SrsDocument $document): string
    {
        $domainBlock = $this->isInventoryDomain($document->title, (string) $document->generated_srs)
            ? $this->inventoryDomainKnowledgeBlock()
            : $this->genericDomainRulesBlock();

        return <<<PROMPT
You are a Senior System Architect in /schema mode. From the SRS and structured requirements, produce PostgreSQL DDL and an OpenAPI 3.0 contract that are strictly aligned with each other.

### CRITICAL RULES
1. NO GENERIC PLACEHOLDERS: Do not invent verbose table names like "inventory_module_sprint_planning". Use concise, domain-driven names from the SRS (e.g. warehouses, stock_levels, transfers).
2. RELATIONSHIP MAPPING: Every FR/NFR must map to concrete tables, columns, constraints, or documented service behavior. If FR-001 mentions a pivot table, implement it with a composite primary key.
3. DDL ↔ OpenAPI ALIGNMENT: Every path in OpenAPI must operate on entities defined in the DDL. Request/response property names and types must match column names and SQL types.
4. TYPE SAFETY: OpenAPI schemas must include property types, formats (uuid, date-time), enums, required fields, and 400/422/500 error response bodies for mutating endpoints.
5. CONSTRAINTS: Include PKs, FKs, CHECK constraints (e.g. non-negative stock), and indexes on FK/filter columns.
6. TRANSACTIONAL NOTE: For FR-003 / concurrent stock mutations, document in OpenAPI descriptions that the implementation uses DB::transaction() with lockForUpdate() — do not omit stock mutation endpoints.

{$domainBlock}

Return ONLY valid JSON (no markdown fences):
{"ddl_sql":"-- PostgreSQL DDL here","openapi_json":"{...stringified OpenAPI 3.0 document...}"}

The openapi_json value must be a JSON string (escaped), not a nested object.
PROMPT;
    }

    /** @param  Collection<int, Requirement>  $requirements */
    public function buildUserPrompt(SrsDocument $document, Collection $requirements): string
    {
        $parts = [
            "Title: {$document->title}",
            '',
            'SRS:',
            (string) $document->generated_srs,
        ];

        if ($requirements->isNotEmpty()) {
            $parts[] = '';
            $parts[] = 'Structured requirements (authoritative for FR/NFR mapping):';
            foreach ($requirements as $requirement) {
                $parts[] = "{$requirement->code} [{$requirement->type}]: {$requirement->body}";
            }
        }

        return implode("\n", $parts);
    }

    public function isInventoryDomain(string $title, string $srs): bool
    {
        $haystack = Str::lower($title.' '.$srs);

        return str_contains($haystack, 'inventory')
            || (str_contains($haystack, 'warehouse') && str_contains($haystack, 'stock_levels'))
            || (str_contains($haystack, 'warehouse') && str_contains($haystack, 'transfer'));
    }

    private function inventoryDomainKnowledgeBlock(): string
    {
        $tables = implode(', ', InventorySchemaBlueprint::CORE_TABLES);
        $statuses = implode(', ', InventorySchemaBlueprint::TRANSFER_STATUSES);

        return <<<BLOCK
### DOMAIN KNOWLEDGE (Inventory Module — use exactly these entities)
Tables: {$tables}

- warehouses: (id UUID PK, name, code UNIQUE, location)
- product_categories: (id UUID PK, name, threshold_multiplier) — FR-005
- products: (id UUID PK, sku UNIQUE, name, category_id FK)
- stock_levels: (warehouse_id FK, product_id FK, quantity) — FR-001 pivot with composite PK (warehouse_id, product_id); quantity >= 0 (NFR-001)
- transfers: (id UUID PK, from_warehouse_id FK, to_warehouse_id FK, status, discrepancy_note) — FR-002 statuses: {$statuses}
- transfer_items: (transfer_id FK, product_id FK, quantity, received_quantity)
- physical_count_imports: (id, warehouse_id FK, status, error_report JSONB) — FR-006
- low_stock_alerts: (id, warehouse_id FK, product_id FK, current_quantity, threshold_quantity) — FR-004 nightly job

OpenAPI paths must include: GET /api/warehouses, GET /api/stock-levels, POST /api/transfers, PATCH /api/transfers/{id}, GET/PATCH /api/product-categories, POST /api/physical-count-imports, GET /api/low-stock-alerts.
BLOCK;
    }

    private function genericDomainRulesBlock(): string
    {
        return <<<'BLOCK'
### DOMAIN KNOWLEDGE
Extract entity names directly from the SRS requirements. Prefer short plural table names. Map each functional requirement to at least one table, column, or API operation.
BLOCK;
    }

    /** @return array{ddl_sql: string, openapi_json: string} */
    private function genericFallback(string $title): array
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $title) ?: 'entity');

        $ddl = <<<SQL
CREATE TABLE {$slug}s (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_{$slug}s_status ON {$slug}s (status);
SQL;

        $openapi = json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => $title, 'version' => '1.0.0'],
            'paths' => [
                "/api/{$slug}s" => [
                    'get' => [
                        'summary' => "List {$title}",
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                    'post' => [
                        'summary' => "Create {$title}",
                        'responses' => [
                            '201' => ['description' => 'Created'],
                            '422' => ['description' => 'Validation error'],
                            '400' => ['description' => 'Bad request'],
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'ddl_sql' => $ddl,
            'openapi_json' => (string) $openapi,
        ];
    }
}
