/** Describe the channel, policy, delivery and escalation projections sent by NotificationPresenter. */
import type { MonitorPageProps } from '../monitors/types';

/** Daily half-open local interval; a later start wraps through midnight. */
export interface QuietHoursView {
    /** Inclusive local time in HH:mm. */
    start: string;
    /** Exclusive local time in HH:mm, distinct from start. */
    end: string;
    /** Explicit IANA timezone used at delivery admission. */
    timezone: string;
}

/** One notification channel; secrets never reach the browser. */
export interface ChannelView {
    /** Team-local channel identifier. */
    id: number;
    /** Channel family: email, slack, telegram, webhook or sms. */
    type: string;
    /** Label chosen by the team. */
    name: string;
    /** Masked destination safe for every member. */
    hint: string;
    /** Whether deliveries use the channel. */
    isActive: boolean;
    /** Whether the family is a declared stub without real delivery. */
    isStub: boolean;
    /** ISO-8601 UTC instant of the current destination's verification, or null. */
    verifiedAt: string | null;
    /** Whether an unexpired verification code is waiting. */
    verificationPending: boolean;
    /** ISO-8601 UTC expiry of the pending code, or null. */
    verificationExpiresAt: string | null;
    /** Non-secret values an edit form may prefill; null for members without management rights. */
    editable: Record<string, string> | null;
    /** Names of the escalation policies notifying the channel. */
    usedBy: string[];
    /** Current daily silence interval, or null when disabled. */
    quietHours: QuietHoursView | null;
}

/** One channel family offered by the creation form. */
export interface ChannelTypeOption {
    /** Stored family value. */
    value: string;
    /** Human-readable family name. */
    label: string;
    /** Whether the family is a declared stub. */
    isStub: boolean;
    /** Fields whose blank value keeps the stored secret on edit. */
    secretFields: string[];
}

/** One channel reference inside a policy step. */
export interface StepChannel {
    /** Team-local channel identifier. */
    id: number;
    /** Channel label. */
    name: string;
    /** Whether the channel currently receives deliveries. */
    isActive: boolean;
}

/** One escalation step. */
export interface PolicyStep {
    /** Minutes after the incident confirmation. */
    afterMinutes: number;
    /** Channels notified by the step. */
    channels: StepChannel[];
}

/** One escalation policy. */
export interface PolicyView {
    /** Team-local policy identifier. */
    id: number;
    /** Label unique within the team. */
    name: string;
    /** Whether monitors without an override use the policy. */
    isDefault: boolean;
    /** Monitors that chose this policy explicitly. */
    monitorCount: number;
    /** Steps in delay order. */
    steps: PolicyStep[];
}

/** Bounds the forms apply before the server applies them again. */
export interface NotificationLimits {
    /** Channels a team may configure. */
    channelsPerTeam: number;
    /** Policies a team may configure. */
    policiesPerTeam: number;
    /** Steps of one policy. */
    stepsPerPolicy: number;
    /** Channels of one step. */
    channelsPerStep: number;
    /** Longest step delay in minutes. */
    maxStepDelayMinutes: number;
    /** Longest channel or policy label in characters. */
    nameMaxLength: number;
    /** Digits of a verification code. */
    codeLength: number;
}

/** One logged notification delivery of an incident. */
export interface DeliveryView {
    /** Log identifier. */
    id: number;
    /** Channel label, or null once the channel is deleted. */
    channelName: string | null;
    /** Channel family snapshot. */
    channelType: string;
    /** Incident moment: opened, escalated or resolved. */
    kind: string;
    /** Zero-based escalation step, or null. */
    stepIndex: number | null;
    /** pending, sent, failed or suppressed. */
    status: string;
    /** Delivery attempts so far. */
    attempts: number;
    /** Redacted provider outcome, or null. */
    response: string | null;
    /** ISO-8601 UTC instant the provider accepted it, or null. */
    sentAt: string | null;
    /** ISO-8601 UTC instant the delivery was recorded, or null. */
    createdAt: string | null;
}

/** Escalation choice of one monitor. */
export interface MonitorEscalationView {
    /** Explicit override, or null to follow the team default. */
    policyId: number | null;
    /** Policy that applies, with whether it is the override or the default; null when nobody is notified. */
    effective: { id: number; name: string; source: 'override' | 'default' } | null;
    /** Team policies offered by the selector. */
    options: { id: number; name: string; isDefault: boolean }[];
}

/** Response of NotificationPageController::index. */
export interface NotificationsPageProps extends MonitorPageProps {
    /** Channels ordered by label. */
    channels: ChannelView[];
    /** Policies ordered by label. */
    policies: PolicyView[];
    /** Monitors that notify nobody because no default exists and they have no override. */
    unnotifiedMonitors: number;
    /** Families offered by the creation form. */
    channelTypes: ChannelTypeOption[];
    /** Form bounds. */
    limits: NotificationLimits;
    /** Interface hints derived from the notification policies. */
    can: { manage: boolean };
    /** Validation and domain errors; `channel` and `policy` carry refusals. */
    errors: Record<string, string>;
}
