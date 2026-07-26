<?php

namespace Tests\Unit\Support;

use App\Support\ClientDebug;
use Tests\TestCase;

class ClientDebugTest extends TestCase
{
    public function test_debug_on_returns_raw_error(): void
    {
        config(['synthesize.debug' => true]);

        $raw = 'Failed to open pull request: {"message":"Validation Failed"}';
        $this->assertSame($raw, ClientDebug::publicError($raw));
        $this->assertSame('secret prompt', ClientDebug::internal('secret prompt'));
    }

    public function test_debug_off_redacts_provider_payloads(): void
    {
        config(['synthesize.debug' => false]);

        $raw = 'Failed to open pull request: {"message":"Validation Failed","errors":[{"message":"A pull request already exists"}]}';
        $safe = ClientDebug::publicError($raw);

        $this->assertNotSame($raw, $safe);
        $this->assertStringNotContainsString('Validation Failed', (string) $safe);
        $this->assertNull(ClientDebug::internal('secret prompt'));
    }

    public function test_debug_off_keeps_safe_operational_messages(): void
    {
        config(['synthesize.debug' => false]);

        $this->assertSame(
            'Cancelled by user.',
            ClientDebug::publicError('Cancelled by user.'),
        );
        $this->assertSame(
            'Skipped at approval — not selected for development.',
            ClientDebug::publicError('Skipped at approval — not selected for development.'),
        );
    }

    public function test_debug_off_redacts_sql_and_tokens(): void
    {
        config(['synthesize.debug' => false]);

        $this->assertSame(
            'Something went wrong. Please try again or contact support.',
            ClientDebug::publicError('SQLSTATE[42703]: Undefined column: included_in_plan'),
        );
        $this->assertSame(
            'Something went wrong. Please try again or contact support.',
            ClientDebug::publicError('token ghp_abcdefghijklmnopqrstuvwxyz012345 leaked'),
        );
    }
}
