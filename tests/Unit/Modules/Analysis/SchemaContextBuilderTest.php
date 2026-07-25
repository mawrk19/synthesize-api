<?php

namespace Tests\Unit\Modules\Analysis;

use App\Modules\Analysis\Services\SchemaContextBuilder;
use App\Modules\Analysis\Support\InventorySchemaBlueprint;
use App\Modules\Documents\Models\SrsDocument;
use Tests\TestCase;

class SchemaContextBuilderTest extends TestCase
{
    private SchemaContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SchemaContextBuilder;
    }

    public function test_detects_inventory_domain_from_srs(): void
    {
        $srs = file_get_contents(base_path('../specs/inventory-srs.md'));

        $this->assertTrue($this->builder->isInventoryDomain('Inventory Module', (string) $srs));
    }

    public function test_inventory_fallback_includes_core_tables_and_openapi_paths(): void
    {
        $document = new SrsDocument([
            'title' => 'Inventory Module',
            'generated_srs' => 'stock_levels pivot table across warehouses with inter-warehouse transfers',
        ]);

        $result = $this->builder->fallbackForDocument($document);

        foreach (InventorySchemaBlueprint::CORE_TABLES as $table) {
            $this->assertStringContainsString("CREATE TABLE {$table}", $result['ddl_sql']);
        }

        $openapi = json_decode($result['openapi_json'], true);
        $this->assertIsArray($openapi);
        $this->assertArrayHasKey('/api/transfers', $openapi['paths']);
        $this->assertArrayHasKey('/api/stock-levels', $openapi['paths']);
        $this->assertArrayHasKey('/api/physical-count-imports', $openapi['paths']);

        $transferSchema = $openapi['components']['schemas']['CreateTransferRequest'] ?? [];
        $this->assertSame(
            ['from_warehouse_id', 'to_warehouse_id', 'items'],
            $transferSchema['required'] ?? [],
        );
    }

    public function test_system_prompt_includes_fr_mapping_rules_for_inventory(): void
    {
        $document = new SrsDocument([
            'title' => 'Inventory Module',
            'generated_srs' => 'stock_levels warehouse transfer',
        ]);

        $prompt = $this->builder->buildSystemPrompt($document);

        $this->assertStringContainsString('stock_levels', $prompt);
        $this->assertStringContainsString('FR-001', $prompt);
        $this->assertStringContainsString('DDL ↔ OpenAPI ALIGNMENT', $prompt);
        $this->assertStringContainsString('POST /api/transfers', $prompt);
    }
}
