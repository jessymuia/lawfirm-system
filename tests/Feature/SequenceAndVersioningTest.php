<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceAndVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_is_strictly_increasing_and_scoped(): void
    {
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->next('invoice'));
        $this->assertSame(2, $seq->next('invoice'));
        $this->assertSame(1, $seq->next('invoice', 2026)); // separate scope resets
        $this->assertSame(1, $seq->next('receipt'));
    }

    public function test_sequence_seed_is_used_only_on_first_call(): void
    {
        $seq = app(SequenceService::class);

        $this->assertSame(43, $seq->next('x', null, fn () => 42));
        $this->assertSame(44, $seq->next('x', null, fn () => 999));
    }

    public function test_invoice_numbers_are_formatted_and_sequential(): void
    {
        $this->assertSame('INV-000001', Invoice::nextInvoiceNumber());
        $this->assertSame('INV-000002', Invoice::nextInvoiceNumber());
    }

    public function test_legacy_api_alias_is_marked_deprecated_and_v1_is_not(): void
    {
        $legacy = $this->postJson('/api/login', ['email' => 'a@b.c', 'password' => 'x']);
        $legacy->assertHeader('Deprecation', 'true');
        $this->assertTrue($legacy->headers->has('Sunset'));

        $v1 = $this->postJson('/api/v1/login', ['email' => 'a@b.c', 'password' => 'x']);
        $this->assertFalse($v1->headers->has('Deprecation'));
    }
}
