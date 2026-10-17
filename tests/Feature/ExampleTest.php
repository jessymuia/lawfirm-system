<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_the_application_returns_a_successful_response()
    {
        // The SPA shell references built assets; the backend jobs do not build the frontend.
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
