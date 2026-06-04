<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Logs\Pages\ListLogs;
use App\Filament\Resources\Logs\Tables\LogsTable;
use App\Models\SyslogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogResourceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOperator(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/admin/logs')->assertRedirect('/admin/login');
    }

    public function test_list_page_renders_entries(): void
    {
        $this->actingAsOperator();

        $entry = SyslogEntry::factory()->create([
            'host' => 'pve',
            'message' => 'watchtower test from pve',
        ]);

        Livewire::test(ListLogs::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$entry]);
    }

    public function test_severity_filter_narrows_results(): void
    {
        $this->actingAsOperator();

        $warning = SyslogEntry::factory()->create(['severity' => 'warning']);
        $info = SyslogEntry::factory()->create(['severity' => 'info']);

        Livewire::test(ListLogs::class)
            ->filterTable('severity', 'warning')
            ->assertCanSeeTableRecords([$warning])
            ->assertCanNotSeeTableRecords([$info]);
    }

    public function test_host_filter_narrows_results(): void
    {
        $this->actingAsOperator();

        $pve = SyslogEntry::factory()->create(['host' => 'pve']);
        $pbs = SyslogEntry::factory()->create(['host' => 'pbs']);

        Livewire::test(ListLogs::class)
            ->filterTable('host', 'pve')
            ->assertCanSeeTableRecords([$pve])
            ->assertCanNotSeeTableRecords([$pbs]);
    }

    public function test_message_search_is_case_insensitive(): void
    {
        $this->actingAsOperator();

        $match = SyslogEntry::factory()->create(['message' => 'Disk usage CRITICAL on root']);
        $other = SyslogEntry::factory()->create(['message' => 'routine cron run completed']);

        Livewire::test(ListLogs::class)
            ->searchTable('critical')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_severity_color_mapping(): void
    {
        $this->assertSame('danger', LogsTable::severityColor('crit'));
        $this->assertSame('danger', LogsTable::severityColor('err'));
        $this->assertSame('warning', LogsTable::severityColor('warning'));
        $this->assertSame('info', LogsTable::severityColor('notice'));
        $this->assertSame('gray', LogsTable::severityColor('info'));
        $this->assertSame('gray', LogsTable::severityColor('debug'));
    }
}
