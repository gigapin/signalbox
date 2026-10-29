<?php

declare(strict_types=1);

/** Persist tenant-owned monitoring configuration without performing checks or scheduling work. */

namespace App\Models;

use App\Casts\Monitoring\MonitorRegionsCast;
use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
use App\Models\Concerns\BelongsToTeam;
use App\Services\Monitoring\MonitorConfigurationMapper;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * Keep scheduling and observation state separate from type-specific immutable configuration values.
 *
 * @property int $id Monitor identity; not a public credential.
 * @property int $team_id Immutable tenant identity supplied by context.
 * @property string $name Human-readable monitor label.
 * @property MonitorType|null $type Configuration variant; required before persistence.
 * @property string|null $url Decrypted HTTP destination, never serialized automatically.
 * @property HttpMethod|null $method HTTP request verb for HTTP/keyword variants.
 * @property array<string, string>|null $headers_json Decrypted HTTP header values.
 * @property string|null $body Decrypted optional request body.
 * @property int|null $expected_status Expected response code, not the last observed result.
 * @property bool|null $follow_redirects Whether protected redirect following is configured.
 * @property string|null $expected_keyword Exact expected text for keyword variants.
 * @property KeywordMode|null $keyword_mode Presence or absence comparison.
 * @property string|null $tls_host Certificate hostname for TLS variants.
 * @property int|null $tls_port TCP port for TLS variants.
 * @property int|null $tls_expiry_warning_days Optional nonnegative expiry warning window in days.
 * @property string|null $dns_name DNS query name for DNS variants.
 * @property DnsRecordType|null $dns_record_type DNS record family.
 * @property list<string>|null $dns_expected_values Nonempty expected DNS record values.
 * @property string|null $dns_resolver Optional resolver destination.
 * @property int|null $heartbeat_grace_seconds Nonnegative tolerance after the shared cadence.
 * @property MonitorRegions $regions_json Nonempty canonical simulated execution region selection.
 * @property int $execution_revision Positive generation invalidating unstarted work after execution edits or pause.
 * @property int $interval_seconds Configured cadence in seconds; required explicitly.
 * @property int $timeout_ms Configured execution timeout in milliseconds; required explicitly.
 * @property int $confirmation_threshold Positive regional failure threshold, initially three.
 * @property bool $is_paused Voluntary pause, distinct from plan suspension.
 * @property bool $is_plan_suspended Suspension due to plan reconciliation, never cleared by voluntary resume.
 * @property MonitorStatus $last_status Cached latest observation, initially unknown.
 * @property int $consecutive_failures Nonnegative legacy summary; not authoritative regional incident state.
 * @property \Carbon\CarbonImmutable|null $last_checked_at Last observation instant in UTC, initially absent.
 * @property \Carbon\CarbonImmutable|null $next_check_at Next scheduled UTC slot; null means not initialized.
 * @property \Carbon\CarbonImmutable|null $ssl_expires_at Last observed certificate expiry in UTC.
 * @property \Carbon\CarbonImmutable|null $last_result_slot_at Latest slot admitted to the observation summary.
 * @property \Carbon\CarbonImmutable|null $created_at UTC creation timestamp.
 * @property \Carbon\CarbonImmutable|null $updated_at UTC modification timestamp.
 * @property-read Team $team Owning team; relation traversal never grants another tenant's data.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Incident> $incidents Incidents opened for this monitor.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MaintenanceWindow> $maintenanceWindows Planned maintenance of this monitor.
 */
final class Monitor extends Model
{
    /** Apply the existing fail-closed query and write ownership boundary. */
    use BelongsToTeam;

    /** @use HasFactory<MonitorFactory> Explicit synthetic configurations without implicit teams. */
    use HasFactory;

    /** Scout database-engine search; the engine keeps no separate index, so writes cost no extra I/O. */
    use Searchable;

    /** @var list<string> Only presentation labels accept ordinary mass assignment. */
    protected $fillable = ['name'];

    /** @var list<string> Configuration requires an explicit authorized projection rather than Eloquent serialization. */
    protected $hidden = [...MonitorConfigurationMapper::COLUMNS, 'last_result_slot_at'];

    /** @var array<string, mixed> Safe initial state; cadence and timeout must still be supplied explicitly. */
    protected $attributes = [
        'execution_revision' => 1,
        'regions_json' => '["eu"]',
        'confirmation_threshold' => 3,
        'is_paused' => false,
        'is_plan_suspended' => false,
        'last_status' => 'unknown',
        'consecutive_failures' => 0,
    ];

    /** Reject incomplete variants even when trusted code assigns individual model properties. */
    protected static function booted(): void
    {
        static::saving(static function (self $monitor): void {
            $monitor->configuration();
        });
    }

    /** @return array<string, string> Native enums, encrypted network fields, typed regions and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'method' => HttpMethod::class,
            'keyword_mode' => KeywordMode::class,
            'dns_record_type' => DnsRecordType::class,
            'url' => 'encrypted',
            'headers_json' => 'encrypted:array',
            'body' => 'encrypted',
            'expected_status' => 'integer',
            'follow_redirects' => 'boolean',
            'tls_port' => 'integer',
            'tls_expiry_warning_days' => 'integer',
            'dns_expected_values' => 'array',
            'heartbeat_grace_seconds' => 'integer',
            'regions_json' => MonitorRegionsCast::class,
            'execution_revision' => 'integer',
            'interval_seconds' => 'integer',
            'timeout_ms' => 'integer',
            'confirmation_threshold' => 'integer',
            'is_paused' => 'boolean',
            'is_plan_suspended' => 'boolean',
            'last_status' => MonitorStatus::class,
            'consecutive_failures' => 'integer',
            'last_checked_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'ssl_expires_at' => 'immutable_datetime',
            'last_result_slot_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Normalize instants before Laravel formats timestamp values without a timezone offset.
     *
     * @param mixed $value Date value accepted by Eloquent, including null and empty attributes.
     * @return string|null UTC timestamp for PostgreSQL, preserving null and empty values.
     */
    public function fromDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /**
     * @param MonitorConfiguration $configuration Complete variant; unrelated columns are cleared before a later save.
     * @throws \InvalidArgumentException When the configuration implementation is unsupported.
     */
    public function applyConfiguration(MonitorConfiguration $configuration): void
    {
        $this->forceFill(MonitorConfigurationMapper::attributes($configuration));
    }

    /**
     * @return MonitorConfiguration Structurally validated variant, with decrypted HTTP values when applicable.
     * @throws \LogicException When required fields are missing.
     * @throws \InvalidArgumentException When persisted values violate structural invariants.
     */
    public function configuration(): MonitorConfiguration
    {
        return MonitorConfigurationMapper::configuration($this);
    }

    /**
     * The database engine turns each key into an `ilike` predicate on the column of the same name, so only
     * plaintext columns may appear: encrypted URL, header and body ciphertext can never match and must not be
     * decrypted for search. Values are irrelevant to that engine; keys alone select the columns.
     *
     * @return array{name: string, tls_host: string|null, dns_name: string|null} Searchable plaintext columns.
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'tls_host' => $this->tls_host,
            'dns_name' => $this->dns_name,
        ];
    }

    /** @return BelongsTo<Team, $this> Owner identity without modifying or authorizing tenant context. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<Incident, $this> Incidents of this monitor, resolved through the tenant scope. */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @return HasMany<MaintenanceWindow, $this> Planned maintenance of this monitor, resolved through the tenant scope. */
    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }
}
