/** Projections sent by the platform admin pages; they carry plan data and counts, never monitor configuration. */

/** Effective entitlement of a team as resolved by TeamEntitlement. */
export interface AdminEntitlement {
    /** Effective plan: free, pro, business or internal. */
    plan: string;
    /** Rule that produced it, e.g. unlimited_override or trial. */
    source: string;
    /** Exclusive ISO-8601 end of the granting rule, or null when open-ended. */
    expiresAt: string | null;
}

/** Stored operator override, active or expired. */
export interface AdminOverride {
    /** unlimited or comped. */
    kind: string;
    /** Granted plan for comped overrides. */
    plan: string | null;
    /** Exclusive ISO-8601 end, or null. */
    expiresAt: string | null;
    /** Operator justification. */
    reason: string;
    /** Last change instant. */
    updatedAt: string | null;
}

/** One directory row. */
export interface AdminTeamSummary {
    /** Team identity. */
    id: number;
    /** Team name. */
    name: string;
    /** URL slug. */
    slug: string;
    /** Primary owner. */
    owner: { name: string; email: string };
    /** Plan stored on the team, before trial and overrides. */
    storedPlan: string;
    /** Effective entitlement. */
    entitlement: AdminEntitlement;
    /** Monitor count, paused and suspended included. */
    monitors: number;
    /** Stored override. */
    override: AdminOverride | null;
}

/** One audit event of a team. */
export interface AdminAuditEvent {
    /** Event identity. */
    id: number;
    /** Dotted action name. */
    action: string;
    /** Acting operator email, or null for command-line actions and deleted users. */
    actor: string | null;
    /** Before/after values. */
    changes: Record<string, unknown>;
    /** Operator justification. */
    reason: string | null;
    /** ISO-8601 instant. */
    createdAt: string | null;
}

/** Team detail page projection. */
export interface AdminTeamDetail extends AdminTeamSummary {
    /** Membership count. */
    members: number;
    /** Trial start. */
    trialStartedAt: string | null;
    /** Exclusive trial end. */
    trialEndsAt: string | null;
    /** Team creation. */
    createdAt: string | null;
    /** Monitor usage against the effective plan. */
    usage: { used: number; limit: number; minimumIntervalSeconds: number };
    /** Latest audit events first. */
    events: AdminAuditEvent[];
}

/** Paginated directory. */
export interface AdminTeamPage {
    /** Rows of the page. */
    data: AdminTeamSummary[];
    /** One-based current page. */
    currentPage: number;
    /** Last page. */
    lastPage: number;
    /** Rows per page. */
    perPage: number;
    /** Matching teams. */
    total: number;
    /** First row number, or null when empty. */
    from: number | null;
    /** Last row number, or null when empty. */
    to: number | null;
}
