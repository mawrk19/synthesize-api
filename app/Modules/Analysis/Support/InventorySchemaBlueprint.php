<?php

namespace App\Modules\Analysis\Support;

/**
 * Canonical PostgreSQL DDL and OpenAPI 3.0 contract for the Inventory Module SRS.
 * Used when AI is unavailable or returns unparseable output.
 */
final class InventorySchemaBlueprint
{
    public const DOMAIN = 'inventory';

    /** @var list<string> */
    public const CORE_TABLES = [
        'warehouses',
        'product_categories',
        'products',
        'stock_levels',
        'transfers',
        'transfer_items',
        'physical_count_imports',
        'low_stock_alerts',
    ];

    /** @var list<string> */
    public const TRANSFER_STATUSES = [
        'PENDING',
        'IN_TRANSIT',
        'PARTIALLY_RECEIVED',
        'COMPLETED',
        'CANCELLED',
    ];

    public static function ddl(): string
    {
        $statusList = implode("', '", self::TRANSFER_STATUSES);

        return <<<SQL
-- Inventory Module (aligned with FR-001 through FR-006)

CREATE TABLE warehouses (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    location VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE product_categories (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    threshold_multiplier NUMERIC(8, 2) NOT NULL DEFAULT 1.00,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE products (
    id UUID PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    category_id UUID NOT NULL REFERENCES product_categories (id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_products_category_id ON products (category_id);

CREATE TABLE stock_levels (
    warehouse_id UUID NOT NULL REFERENCES warehouses (id),
    product_id UUID NOT NULL REFERENCES products (id),
    quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (warehouse_id, product_id)
);

CREATE INDEX idx_stock_levels_product_id ON stock_levels (product_id);

CREATE TABLE transfers (
    id UUID PRIMARY KEY,
    from_warehouse_id UUID NOT NULL REFERENCES warehouses (id),
    to_warehouse_id UUID NOT NULL REFERENCES warehouses (id),
    status VARCHAR(30) NOT NULL DEFAULT 'PENDING'
        CHECK (status IN ('{$statusList}')),
    discrepancy_note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CHECK (from_warehouse_id <> to_warehouse_id)
);

CREATE INDEX idx_transfers_status ON transfers (status);
CREATE INDEX idx_transfers_from_warehouse_id ON transfers (from_warehouse_id);
CREATE INDEX idx_transfers_to_warehouse_id ON transfers (to_warehouse_id);

CREATE TABLE transfer_items (
    transfer_id UUID NOT NULL REFERENCES transfers (id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES products (id),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    received_quantity INTEGER NOT NULL DEFAULT 0 CHECK (received_quantity >= 0),
    PRIMARY KEY (transfer_id, product_id),
    CHECK (received_quantity <= quantity)
);

CREATE TABLE physical_count_imports (
    id UUID PRIMARY KEY,
    warehouse_id UUID NOT NULL REFERENCES warehouses (id),
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    error_report JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_physical_count_imports_warehouse_id ON physical_count_imports (warehouse_id);

CREATE TABLE low_stock_alerts (
    id UUID PRIMARY KEY,
    warehouse_id UUID NOT NULL REFERENCES warehouses (id),
    product_id UUID NOT NULL REFERENCES products (id),
    current_quantity INTEGER NOT NULL CHECK (current_quantity >= 0),
    threshold_quantity INTEGER NOT NULL CHECK (threshold_quantity >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_low_stock_alerts_warehouse_product ON low_stock_alerts (warehouse_id, product_id);
SQL;
    }

    public static function openapiJson(): string
    {
        return json_encode(self::openapi(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function openapi(): array
    {
        $transferStatus = [
            'type' => 'string',
            'enum' => self::TRANSFER_STATUSES,
        ];

        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $errorResponse = [
            'description' => 'Validation error',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Inventory Module API',
                'version' => '1.0.0',
                'description' => 'API contract aligned with inventory SRS (FR-001 through FR-006).',
            ],
            'paths' => [
                '/api/warehouses' => [
                    'get' => [
                        'summary' => 'List warehouses',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => '#/components/schemas/Warehouse'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/stock-levels' => [
                    'get' => [
                        'summary' => 'Query stock levels by warehouse and/or product',
                        'parameters' => [
                            ['name' => 'warehouse_id', 'in' => 'query', 'schema' => $uuid],
                            ['name' => 'product_id', 'in' => 'query', 'schema' => $uuid],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => '#/components/schemas/StockLevel'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '400' => ['description' => 'Bad request'],
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/transfers' => [
                    'post' => [
                        'summary' => 'Create inter-warehouse transfer (FR-002, FR-003)',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CreateTransferRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Transfer'],
                                    ],
                                ],
                            ],
                            '400' => ['description' => 'Bad request'],
                            '422' => $errorResponse,
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/transfers/{id}' => [
                    'patch' => [
                        'summary' => 'Update transfer status or record partial receipt',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $uuid],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/UpdateTransferRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Transfer'],
                                    ],
                                ],
                            ],
                            '400' => ['description' => 'Bad request'],
                            '422' => $errorResponse,
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/product-categories' => [
                    'get' => [
                        'summary' => 'List product categories with threshold multipliers (FR-005)',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => '#/components/schemas/ProductCategory'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/product-categories/{id}' => [
                    'patch' => [
                        'summary' => 'Update category threshold multiplier (FR-005)',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $uuid],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['threshold_multiplier'],
                                        'properties' => [
                                            'threshold_multiplier' => ['type' => 'number', 'format' => 'float', 'minimum' => 0],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            '422' => $errorResponse,
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/physical-count-imports' => [
                    'post' => [
                        'summary' => 'Upload chunked physical count CSV (FR-006)',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['warehouse_id', 'file'],
                                        'properties' => [
                                            'warehouse_id' => $uuid,
                                            'file' => ['type' => 'string', 'format' => 'binary'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '202' => ['description' => 'Import accepted for processing'],
                            '422' => $errorResponse,
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
                '/api/low-stock-alerts' => [
                    'get' => [
                        'summary' => 'List low-stock alerts from nightly job (FR-004)',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => '#/components/schemas/LowStockAlert'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '500' => ['description' => 'Server error'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Warehouse' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => $uuid,
                            'name' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'location' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'ProductCategory' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => $uuid,
                            'name' => ['type' => 'string'],
                            'threshold_multiplier' => ['type' => 'number', 'format' => 'float'],
                        ],
                    ],
                    'StockLevel' => [
                        'type' => 'object',
                        'properties' => [
                            'warehouse_id' => $uuid,
                            'product_id' => $uuid,
                            'quantity' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                    'TransferItem' => [
                        'type' => 'object',
                        'required' => ['product_id', 'quantity'],
                        'properties' => [
                            'product_id' => $uuid,
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                            'received_quantity' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                    'CreateTransferRequest' => [
                        'type' => 'object',
                        'required' => ['from_warehouse_id', 'to_warehouse_id', 'items'],
                        'properties' => [
                            'from_warehouse_id' => $uuid,
                            'to_warehouse_id' => $uuid,
                            'items' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['product_id', 'quantity'],
                                    'properties' => [
                                        'product_id' => $uuid,
                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'UpdateTransferRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => $transferStatus,
                            'discrepancy_note' => ['type' => 'string', 'nullable' => true],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['product_id', 'received_quantity'],
                                    'properties' => [
                                        'product_id' => $uuid,
                                        'received_quantity' => ['type' => 'integer', 'minimum' => 0],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'Transfer' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => $uuid,
                            'from_warehouse_id' => $uuid,
                            'to_warehouse_id' => $uuid,
                            'status' => $transferStatus,
                            'discrepancy_note' => ['type' => 'string', 'nullable' => true],
                            'items' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/TransferItem'],
                            ],
                        ],
                    ],
                    'LowStockAlert' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => $uuid,
                            'warehouse_id' => $uuid,
                            'product_id' => $uuid,
                            'current_quantity' => ['type' => 'integer', 'minimum' => 0],
                            'threshold_quantity' => ['type' => 'integer', 'minimum' => 0],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
