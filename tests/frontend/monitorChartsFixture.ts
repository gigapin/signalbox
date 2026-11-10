/** Provide exact synthetic chart statistics with measured zero, gaps and uneven sample counts. */
import type {
    MonitorMetricsView,
    MonitorLatencyStatistics,
} from '../../resources/js/components/monitors/metrics';
import { detailMetrics, detailProps } from './monitorDetailFixture';

/** Empty latency is nullable, never a fabricated zero. */
const EMPTY: MonitorLatencyStatistics = { meanMs: null, p50Ms: null, p95Ms: null, samples: 0 };

/**
 * @param period Supported rolling period.
 * @param region Selected latency region; availability remains unchanged.
 * @returns A complete safe snapshot; daily buckets before the recent monitor's creation contain zero monitored time.
 */
export function chartMetrics(
    period: '24h' | '7d' | '30d' = '24h',
    region: 'all' | 'eu' | 'us' = 'all',
): MonitorMetricsView {
    const size = period === '24h' ? 24 : Number.parseInt(period, 10);
    const bucketMs = period === '24h' ? 3600000 : 86400000;
    const end = Date.parse(detailMetrics.generatedAt);
    const series = Array.from({ length: size }, (_, index) => {
        let eu = EMPTY;
        let us = EMPTY;
        if (period === '24h' && index === 0) eu = { meanMs: 0, p50Ms: 0, p95Ms: 0, samples: 1 };
        if (period === '24h' && index === 2)
            eu = { meanMs: 100, p50Ms: 100, p95Ms: 100, samples: 1 };
        if (index === size - 1) {
            eu = { meanMs: 200, p50Ms: 100, p95Ms: 500, samples: 3 };
            us = { meanMs: 25, p50Ms: 0, p95Ms: 50, samples: 2 };
        }
        return {
            startsAt: new Date(end - (size - index) * bucketMs).toISOString(),
            endsAt: new Date(end - (size - index - 1) * bucketMs).toISOString(),
            eu: region === 'us' ? EMPTY : eu,
            us: region === 'eu' ? EMPTY : us,
        };
    });
    const uptime =
        period === '24h'
            ? detailMetrics.recent
                  .map((point, index) => ({
                      ...point,
                      observedPercent: index === 1 ? 50 : 100,
                      coveragePercent: index === 1 ? 66.66 : 100,
                      slaPercent: index === 1 ? null : 100,
                  }))
                  .map((point, index) =>
                      index === 2
                          ? {
                                ...point,
                                observedPercent: null,
                                coveragePercent: 0,
                                slaPercent: null,
                            }
                          : point,
                  )
                  .map((point, index) =>
                      index === 3
                          ? {
                                ...point,
                                observedPercent: null,
                                coveragePercent: null,
                                slaPercent: null,
                            }
                          : point,
                  )
            : series.map((point, index) =>
                  index === size - 1
                      ? {
                            ...detailMetrics.periods[period],
                            startsAt: point.startsAt,
                            endsAt: point.endsAt,
                        }
                      : {
                            startsAt: point.startsAt,
                            endsAt: point.endsAt,
                            available: true,
                            observedPercent: null,
                            coveragePercent: null,
                            slaPercent: null,
                            durations: {
                                upSeconds: 0,
                                downSeconds: 0,
                                unknownSeconds: 0,
                                excludedSeconds: 0,
                            },
                        },
              );
    return {
        ...detailMetrics,
        selection: { period, region },
        supportedPeriods: ['24h', '7d', '30d'],
        uptime: { available: true, series: uptime },
        latency: {
            available: true,
            source: 'raw',
            series,
            summary: {
                eu:
                    region === 'us'
                        ? EMPTY
                        : {
                              meanMs: period === '24h' ? 140 : 200,
                              p50Ms: 100,
                              p95Ms: 500,
                              samples: period === '24h' ? 5 : 3,
                          },
                us: region === 'eu' ? EMPTY : { meanMs: 25, p50Ms: 0, p95Ms: 50, samples: 2 },
            },
        },
    };
}

/** Full page props with the same safe identity/return context used by availability-card tests. */
export const chartsProps = { ...detailProps, metrics: chartMetrics() };
