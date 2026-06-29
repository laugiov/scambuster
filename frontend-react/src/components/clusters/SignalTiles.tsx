import { humanizeContext, normalizeContextKey, STIMULUS_COLORS } from '@/lib/iocContextLabels';
import { isUrgencyPlaceholder } from '@/lib/clusterUrgencyHeuristic';

interface BehavioralProfile {
  dominant_stimulus: string | null;
  dominant_stimulus_count: number;
  dominant_revelation_turn: number | null;
  avg_urgency_score: number | null;
  hesitation_count: number;
  language_switch_count: number;
  templated_excerpt_count: number;
  total_excerpt_variant_count?: number | null;
}

interface AnchorIoc {
  avg_urgency_score: number | null;
}

interface SignalTilesProps {
  profile: BehavioralProfile;
  anchors: AnchorIoc[];
  conversationCount: number;
}

function pressureLabel(score: number | null): string {
  if (score === null || score === undefined) return '';
  if (score < 0.3) return 'low pressure';
  if (score < 0.6) return 'medium pressure';
  return 'high pressure';
}

function Tile({
  label,
  value,
  sub,
  warn = false,
  testId,
}: {
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
  warn?: boolean;
  testId: string;
}) {
  const bg = warn ? 'bg-warning/10' : 'bg-surface-low';
  const labelColor = warn ? 'text-warning' : 'text-on-surface-dim';
  const valueColor = warn ? 'text-warning' : 'text-on-surface';

  return (
    <div data-testid={testId} className={`${bg} rounded-lg px-4 py-3`}>
      <div className={`text-[0.7rem] uppercase tracking-wider ${labelColor}`}>{label}</div>
      <div className={`text-lg font-medium mt-1 ${valueColor}`}>{value}</div>
      {sub && <div className="text-xs text-on-surface-dim mt-0.5 tabular-nums">{sub}</div>}
    </div>
  );
}

/**
 * 4-tile grid below the verdict on /clusters/{id}:
 *   - Primary tactic    (dominant_stimulus)
 *   - First IOC reveal  (dominant_revelation_turn)
 *   - Avg urgency       (with placeholder detection)
 *   - Automation        (template signal, warning style)
 *
 * Tile slots are kept stable (no layout shift). When a value is missing or
 * unreliable, the tile renders a placeholder marker instead of being removed
 * — except the Automation tile which is hidden entirely when
 * templated_excerpt_count <= 1 (no meaningful template signal).
 */
export function SignalTiles({ profile, anchors, conversationCount }: SignalTilesProps) {
  const tiles: React.ReactNode[] = [];

  const stim = profile.dominant_stimulus;
  if (stim) {
    const stimClass = STIMULUS_COLORS[normalizeContextKey(stim)] ?? 'bg-surface-dim text-on-surface-variant';
    tiles.push(
      <Tile
        key="tactic"
        testId="signal-tile-tactic"
        label="Primary tactic"
        value={<span className={`px-2 py-0.5 rounded text-sm font-medium ${stimClass}`}>{humanizeContext(stim)}</span>}
        sub={`${profile.dominant_stimulus_count} / ${conversationCount} conversations`}
      />,
    );
  } else {
    tiles.push(<Tile key="tactic" testId="signal-tile-tactic" label="Primary tactic" value="—" sub="not detected" />);
  }

  const turn = profile.dominant_revelation_turn;
  if (turn !== null && turn !== undefined) {
    tiles.push(
      <Tile
        key="reveal"
        testId="signal-tile-reveal"
        label="First IOC reveal"
        value={`Turn ${turn}`}
        sub={turn === 1 ? 'initial email' : undefined}
      />,
    );
  } else {
    tiles.push(<Tile key="reveal" testId="signal-tile-reveal" label="First IOC reveal" value="—" sub="no data" />);
  }

  const placeholder = isUrgencyPlaceholder(profile, anchors);
  if (placeholder) {
    tiles.push(
      <Tile
        key="urgency"
        testId="signal-tile-urgency-placeholder"
        label="Avg urgency"
        value={<span title="Urgency aggregation under review — backend ticket open">—</span>}
        sub="under review"
      />,
    );
  } else if (profile.avg_urgency_score !== null && profile.avg_urgency_score !== undefined) {
    const pct = Math.round(profile.avg_urgency_score * 100);
    tiles.push(
      <Tile
        key="urgency"
        testId="signal-tile-urgency"
        label="Avg urgency"
        value={`${pct}%`}
        sub={pressureLabel(profile.avg_urgency_score)}
      />,
    );
  } else {
    tiles.push(<Tile key="urgency" testId="signal-tile-urgency" label="Avg urgency" value="—" sub="no data" />);
  }

  if (profile.templated_excerpt_count > 1) {
    const variants = profile.total_excerpt_variant_count;
    const sub =
      variants && variants > 0
        ? `${profile.templated_excerpt_count} IOCs across ${variants} excerpt variant${variants === 1 ? '' : 's'}`
        : `${profile.templated_excerpt_count} IOCs share an excerpt`;
    tiles.push(
      <Tile
        key="automation"
        testId="signal-tile-automation"
        label="Automation"
        value="Templated"
        sub={sub}
        warn
      />,
    );
  }

  return (
    <div
      data-testid="signal-tiles"
      className={`grid gap-3 ${tiles.length >= 4 ? 'grid-cols-2 lg:grid-cols-4' : tiles.length === 3 ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2'}`}
    >
      {tiles}
    </div>
  );
}
