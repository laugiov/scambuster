import { useClusterTemporal } from '@/hooks/useClusterTemporal';

interface TemporalPanelProps {
  clusterId: string;
}

const DOW = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const ACCENT_RGB = '173, 198, 255'; // --color-accent

/** Heat scale for the hour chart: cool blue (quiet) → amber → red (busy). */
function heatColor(ratio: number): string {
  if (ratio <= 0) return 'rgba(96, 165, 250, 0.18)';
  const stops = [
    [96, 165, 250], // info blue  (low)
    [251, 191, 36], // amber      (mid)
    [248, 113, 113], // red        (high)
  ];
  const seg = ratio < 0.5 ? 0 : 1;
  const t = ratio < 0.5 ? ratio * 2 : (ratio - 0.5) * 2;
  const [a, b] = [stops[seg], stops[seg + 1]];
  const c = a.map((v, i) => Math.round(v + (b[i] - v) * t));
  return `rgb(${c[0]}, ${c[1]}, ${c[2]})`;
}

function fmtDate(iso: string | null): string {
  if (!iso) return '--';
  return new Date(iso).toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: '2-digit' });
}

/** A headline metric: big value, small caption, optional hover explainer. */
function Hero({ value, caption, tone, hint }: { value: React.ReactNode; caption: string; tone?: 'accent' | 'warning'; hint?: string }) {
  const color = tone === 'accent' ? 'text-accent' : tone === 'warning' ? 'text-warning' : 'text-on-surface';
  return (
    <div className="flex flex-col" title={hint}>
      <span className={`text-3xl font-bold leading-none tabular-nums ${color}`}>{value}</span>
      <span className="mt-1.5 flex items-center gap-1 text-[10px] uppercase tracking-wide text-on-surface-dim">
        {caption}
        {hint && <span className="opacity-60" aria-hidden="true">ⓘ</span>}
      </span>
    </div>
  );
}

/**
 * 24-bar hour-of-day heatmap. Bar opacity scales with volume (busy hours glow in
 * the accent colour, quiet hours fade), the peak hour is solid + ringed. This is
 * the visual heart of the panel — the actor's daily rhythm at a glance.
 */
function HourHeatmap({ histogram, peakHour }: { histogram: Record<string, number>; peakHour: number | null }) {
  const counts = Array.from({ length: 24 }, (_, h) => histogram[String(h)] ?? 0);
  const max = Math.max(1, ...counts);

  return (
    <div data-testid="temporal-hour-chart">
      <div className="flex items-end gap-px" style={{ height: 56 }}>
        {counts.map((c, h) => {
          const ratio = c / max;
          const isPeak = peakHour === h;
          const heightPct = c === 0 ? 6 : 20 + ratio * 80;
          return (
            <div key={h} className="flex flex-1 items-end" style={{ height: '100%' }}>
              <div
                title={`${String(h).padStart(2, '0')}:00 — ${c} msg`}
                data-peak={isPeak ? 'true' : undefined}
                className="w-full rounded-t-sm"
                style={{
                  height: `${heightPct}%`,
                  backgroundColor: heatColor(ratio),
                  boxShadow: isPeak ? '0 0 6px 1px rgba(248, 113, 113, 0.7)' : undefined,
                }}
              />
            </div>
          );
        })}
      </div>
      <div className="mt-1 flex justify-between text-[9px] tabular-nums text-on-surface-dim">
        <span>00h</span>
        <span>06h</span>
        <span>12h</span>
        <span>18h</span>
        <span>23h</span>
      </div>
    </div>
  );
}

/**
 * "Activity Pattern" panel on the cluster (threat-actor) detail page.
 * Surfaces the on-read temporal / burst / cadence analysis (GET /clusters/{id}/temporal).
 */
export function TemporalPanel({ clusterId }: TemporalPanelProps) {
  const { data: t, isLoading } = useClusterTemporal(clusterId);

  if (isLoading) {
    return null;
  }

  if (!t) {
    return (
      <section
        data-testid="temporal-empty"
        className="rounded-lg border border-dashed border-border bg-surface-low px-5 py-4 text-sm text-on-surface-dim"
      >
        No inbound activity recorded for this actor yet.
      </section>
    );
  }

  const peakHour = t.peak_hour !== null ? `${String(t.peak_hour).padStart(2, '0')}:00` : '--';
  const peakDay = t.peak_day_of_week !== null ? (DOW[t.peak_day_of_week] ?? '--') : '--';
  const dowMax = Math.max(1, ...Object.values(t.day_of_week_histogram));

  return (
    <section data-testid="temporal-panel" className="overflow-hidden rounded-lg border border-border bg-surface-low">
      <div className="flex items-center justify-between border-b border-info/25 bg-info/10 px-5 py-2.5">
        <h2 className="flex items-center gap-2.5 text-sm font-semibold uppercase tracking-wide text-info" title="When this actor operates — activity, cadence and burst detection computed from their inbound (scammer) message timestamps.">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-info/20 text-info">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </span>
          Activity Pattern
        </h2>
        <span className="text-[11px] text-on-surface-dim">
          {fmtDate(t.first_activity)} – {fmtDate(t.last_activity)}
        </span>
      </div>
      <div className="px-5 pb-4 pt-3">

      {/* Hero row — the three numbers that tell the story, big and colour-coded. */}
      <div className="mt-2 flex flex-wrap items-end gap-x-10 gap-y-4">
        <Hero value={t.message_count} caption="Inbound messages" tone="accent" hint="Total inbound (scammer) messages — how persistent this actor was across the whole engagement." />
        <Hero value={`${t.active_days} / ${t.active_span_days}`} caption="Active days / span" hint="Distinct days the actor was active, out of the calendar span from first to last message." />
        <Hero
          value={<span data-testid="temporal-burst-count">{t.burst_count > 0 ? `⚡ ${t.burst_count}` : t.burst_count}</span>}
          caption="Burst days"
          tone={t.burst_count > 0 ? 'warning' : undefined}
          hint="Days with ≥ 2× the actor's median daily volume — campaign spikes. Clustered bursts often mark a coordinated push."
        />
        <div className="ml-auto flex items-end gap-6">
          <Hero value={peakHour} caption="Peak hour" tone="accent" hint="Busiest hour of day. A tight active band is a working-hours / single-timezone signature." />
          <Hero value={peakDay} caption="Peak weekday" tone="accent" hint="Busiest weekday. Mon–Fri concentration suggests a business-hours operation; weekend activity is common for romance/investment scams." />
        </div>
      </div>

      {/* The daily-rhythm heatmap. */}
      <div className="mt-4">
        <div className="mb-1.5 flex items-center justify-between text-[10px] uppercase tracking-wide text-on-surface-dim">
          <span className="flex items-center gap-1" title="Message volume by hour of day. Activity clustered into a ~9-hour band is a working-hours signature (single timezone / office); a flat 24-hour spread suggests automation.">
            Messages by hour of day <span className="opacity-60" aria-hidden="true">ⓘ</span>
          </span>
          <span className="flex items-center gap-1.5 tracking-normal normal-case">
            <span>quiet</span>
            <span className="h-2 w-16 rounded-full" style={{ background: 'linear-gradient(to right, rgba(96,165,250,0.5), rgb(251,191,36), rgb(248,113,113))' }} />
            <span>busy</span>
          </span>
        </div>
        <HourHeatmap histogram={t.hour_of_day_histogram} peakHour={t.peak_hour} />
      </div>

      {/* Weekday strip — thin, peak highlighted. */}
      <div className="mt-3 flex items-end gap-1.5" data-testid="temporal-dow-strip">
        {[1, 2, 3, 4, 5, 6, 7].map((d) => {
          const c = t.day_of_week_histogram[String(d)] ?? 0;
          const isPeak = t.peak_day_of_week === d;
          return (
            <div key={d} className="flex flex-1 flex-col items-center gap-1">
              <div className="flex h-6 w-full items-end overflow-hidden rounded-sm bg-surface-base">
                <div
                  className="w-full rounded-sm"
                  style={{
                    height: `${Math.max(c > 0 ? 14 : 0, (c / dowMax) * 100)}%`,
                    backgroundColor: `rgba(${ACCENT_RGB}, ${isPeak ? 1 : 0.3})`,
                  }}
                  title={`${DOW[d]} — ${c} msg`}
                />
              </div>
              <span className={`text-[9px] ${isPeak ? 'font-semibold text-accent' : 'text-on-surface-dim'}`}>{DOW[d]}</span>
            </div>
          );
        })}
      </div>

      {/* Cadence — demoted to a single muted line; detail, not headline. */}
      <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border pt-2.5 text-[11px] text-on-surface-dim">
        <span title="The single most active date and its message count.">Busiest day <span className="font-medium text-on-surface-variant">{t.busiest_day ? `${fmtDate(t.busiest_day)} · ${t.max_messages_per_day}` : '--'}</span></span>
        <span className="text-border">·</span>
        <span title="Typical time between consecutive messages — the reply cadence. Minutes = an engaged live operator; hours = batch / asynchronous.">Median gap <span className="font-medium text-on-surface-variant">{t.median_gap_hours !== null ? `${t.median_gap_hours.toFixed(1)} h` : '--'}</span></span>
        <span className="text-border">·</span>
        <span title="The longest dormancy between messages — was the actor continuous, or did they go quiet and resurface?">Longest gap <span className="font-medium text-on-surface-variant">{t.longest_dormancy_hours !== null ? `${Math.round(t.longest_dormancy_hours)} h` : '--'}</span></span>
        {t.burst_days.length > 0 && (
          <span className="ml-auto flex flex-wrap items-center gap-1.5">
            <span className="text-warning">⚡ Bursts</span>
            {t.burst_days.map((d) => (
              <span key={d} className="rounded-full border border-warning/40 bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning">
                {fmtDate(d)}
              </span>
            ))}
          </span>
        )}
      </div>
      </div>
    </section>
  );
}
