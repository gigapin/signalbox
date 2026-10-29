<?php

declare(strict_types=1);

/** Describe the validated destination and credentials of one notification channel. */

namespace App\Contracts\Notifications;

use App\Enums\Notifications\ChannelType;

/**
 * Implementations are immutable values built by ChannelConfigurationParser. Only `toArray()` contains secrets,
 * and it is written solely to the encrypted `config_json` column or handed to a delivery adapter.
 */
interface ChannelConfiguration
{
    /** @return ChannelType Channel family this configuration belongs to. */
    public function type(): ChannelType;

    /** @return array<string, string> Complete persisted values, secrets included. */
    public function toArray(): array;

    /** @return string Masked destination safe to show to every team member. */
    public function hint(): string;

    /** @return array<string, string> Non-secret values a manager's edit form may prefill; secrets are omitted. */
    public function editable(): array;

    /**
     * @param self $other Configuration compared field by field.
     * @return bool Whether both describe the same destination and credentials.
     */
    public function sameAs(self $other): bool;
}
