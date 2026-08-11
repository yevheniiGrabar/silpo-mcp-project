<?php

it('exposes a health heartbeat for the BFF', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'app', 'time']);
});

it('exposes the framework health probe', function () {
    $this->get('/up')->assertOk();
});
