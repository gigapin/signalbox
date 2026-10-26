<?php

declare(strict_types=1);

/** Build synthetic monitor variants only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Models\Monitor;
use App\Services\Monitoring\HeartbeatLifecycle;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Monitor> Keep fixture creation independent of network I/O and implicit team provisioning. */
final class MonitorFactory extends Factory
{
    /** @return array<string, mixed> Explicit fixture cadence and timeout; the production model supplies no timing defaults. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'name' => 'Synthetic monitor',
            'interval_seconds' => 300,
            'timeout_ms' => 5000,
        ];
    }

    /** @return static Default to a complete HTTP variant before Laravel factory persistence. */
    public function configure(): static
    {
        return $this->http()->afterCreating(static function (Monitor $monitor): void {
            // Persist the same arming state that the authorized production creation action supplies.
            app(HeartbeatLifecycle::class)->synchronize($monitor);
        });
    }

    /** @return static Explicit HTTP fixture using a reserved synthetic destination. */
    public function http(): static
    {
        return $this->variant(new HttpMonitorConfiguration('https://example.invalid/health'));
    }

    /** @return static Explicit keyword fixture composed with the same synthetic HTTP transport. */
    public function keyword(): static
    {
        return $this->variant(new KeywordMonitorConfiguration(new HttpMonitorConfiguration('https://example.invalid/health'), 'healthy'));
    }

    /** @return static Explicit TLS fixture; the port is supplied rather than defaulted by production configuration. */
    public function tls(): static
    {
        return $this->variant(new TlsMonitorConfiguration('example.invalid', 443));
    }

    /** @return static Explicit DNS fixture using reserved documentation values. */
    public function dns(): static
    {
        return $this->variant(new DnsMonitorConfiguration('example.invalid', DnsRecordType::A, ['192.0.2.1']));
    }

    /** @return static Explicit heartbeat fixture with zero lateness tolerance. */
    public function heartbeat(): static
    {
        return $this->variant(new HeartbeatMonitorConfiguration(0));
    }

    /**
     * @param MonitorConfiguration $configuration Complete synthetic variant, applied before persistence.
     * @return static Factory clone whose after-making callbacks replace earlier variant settings.
     */
    private function variant(MonitorConfiguration $configuration): static
    {
        return $this->afterMaking(static function (Monitor $monitor) use ($configuration): void {
            $monitor->applyConfiguration($configuration);
        });
    }
}
