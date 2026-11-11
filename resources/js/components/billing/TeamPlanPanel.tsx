/** Present an authorized plan snapshot without inferring payments, subscriptions or operator permissions. */
import Panel from '../ui/Panel';
import { formatInstant } from '../incidents/time';
import { planLabel, sourceLabel } from './labels';

/** Member-safe plan response; override justifications and billing identities are deliberately absent. */
export interface TeamPlanSummary {
    /** Recorded base plan before trial and overrides; not evidence of a paid invoice. */
    storedPlan: string;
    /** Effective plan from TeamEntitlement. */
    effectivePlan: string;
    /** Authoritative rule producing the effective plan. */
    source: string;
    /** Exclusive expiry of the granting rule, or open-ended. */
    expiresAt: string | null;
    /** Recorded initial trial, classified at the same server snapshot. */
    trial: { state: string; startsAt: string | null; endsAt: string | null };
    /** Count includes paused and suspended monitors. */
    monitors: { used: number; limit: number };
    /** Count includes draft status pages. */
    statusPages: { used: number; limit: number };
    /** Plan cadence floor in seconds. */
    minimumIntervalSeconds: number;
    /** Readable aggregate history in days, not raw-result retention. */
    rollupRetentionDays: number;
    /** UTC server evaluation instant. */
    generatedAt: string;
}

/** No amount, renewal date, payment state or management action is synthesized from an entitlement. */
export default function TeamPlanPanel({ summary }: { summary?: TeamPlanSummary }) {
    return (
        <Panel
            title="Plan and usage"
            description="Current entitlement and quotas for this team. Counts include paused/suspended monitors and draft status pages."
        >
            {!summary ? (
                <p>
                    Plan information is unavailable in this saved page. Reload to check the current
                    limits.
                </p>
            ) : (
                <>
                    <dl className="ui-audit-snapshots">
                        <div>
                            <dt className="ui-help">Effective plan</dt>
                            <dd>
                                {planLabel(summary.effectivePlan)} · {sourceLabel(summary.source)}
                            </dd>
                        </div>
                        <div>
                            <dt className="ui-help">Stored plan</dt>
                            <dd>{planLabel(summary.storedPlan)}</dd>
                        </div>
                        <div>
                            <dt className="ui-help">Entitlement expiry</dt>
                            <dd>
                                {summary.expiresAt === null
                                    ? 'No scheduled expiry'
                                    : formatInstant(summary.expiresAt, 'Not recorded')}
                            </dd>
                        </div>
                        <div>
                            <dt className="ui-help">Initial trial</dt>
                            <dd>
                                {summary.trial.state === 'active' ? 'Active' : 'Ended'} ·{' '}
                                {formatInstant(summary.trial.startsAt, 'Not recorded')} –{' '}
                                {formatInstant(summary.trial.endsAt, 'Not recorded')}
                            </dd>
                        </div>
                        <div>
                            <dt className="ui-help">Monitors</dt>
                            <dd>
                                {summary.monitors.used} of {summary.monitors.limit}
                                {summary.monitors.used > summary.monitors.limit &&
                                    ' · Above current quota'}
                            </dd>
                        </div>
                        <div>
                            <dt className="ui-help">Status pages</dt>
                            <dd>
                                {summary.statusPages.used} of {summary.statusPages.limit}
                                {summary.statusPages.used > summary.statusPages.limit &&
                                    ' · Above current quota'}
                            </dd>
                        </div>
                        <div>
                            <dt className="ui-help">Minimum interval</dt>
                            <dd>{summary.minimumIntervalSeconds} seconds</dd>
                        </div>
                        <div>
                            <dt className="ui-help">Rollup history</dt>
                            <dd>{summary.rollupRetentionDays} days</dd>
                        </div>
                    </dl>
                    <p className="ui-help mt-4">
                        Snapshot {formatInstant(summary.generatedAt, 'Not recorded')}, in your local
                        time. Limits are checked again when you make a change. The trial dates are
                        retained even when another entitlement applies.
                    </p>
                    {summary.source === 'subscription' && (
                        <p className="ui-help">
                            The stored plan determines access; this view does not confirm a payment
                            or a renewal date.
                        </p>
                    )}
                    {summary.effectivePlan === 'internal' && (
                        <p className="ui-help">
                            The testing profile has finite limits shown above.
                        </p>
                    )}
                </>
            )}
        </Panel>
    );
}
