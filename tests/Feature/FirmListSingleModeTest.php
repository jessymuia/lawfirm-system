<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** UI-16: a single-firm install has one firm, so the switcher never shows and there is nowhere to switch to. */
class FirmListSingleModeTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_one_firm_and_no_switching(): void
    {
        $this->actingAs($this->userWithPermissions([]));
        $firms = $this->getJson('/api/v1/firms')->assertOk()->json('data');
        $this->assertCount(1, $firms);
        $this->assertTrue($firms[0]['current']);
        $this->postJson('/api/v1/firms/elsewhere/switch')->assertNotFound();
        $this->get('/switch/'.str_repeat('a', 48))->assertRedirect('/login?switch=invalid');
    }
}
