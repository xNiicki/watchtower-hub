<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppEvents\Pages\ListAppEvents;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppEventResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/admin/app-events')->assertRedirect('/admin/login');
    }

    public function test_list_renders_events(): void
    {
        $this->actingAs(User::factory()->create());
        $app = MonitoredApp::factory()->create();
        $event = AppEvent::factory()->for($app, 'app')->create(['title' => 'TypeError']);

        Livewire::test(ListAppEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$event]);
    }
}
