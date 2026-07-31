interface ProfileLike {
  avg_urgency_score?: number | null;
}

interface AnchorLike {
  avg_urgency_score?: number | null;
}

const PLACEHOLDER_VALUE = 0.2;
const EPSILON = 1e-6;

/**
 * Heuristic: detect whether the cluster's urgency aggregate is a backend
 * placeholder rather than a real LLM-derived signal.
 *
 * Today the production data shows avg_urgency_score == 0.20 across every
 * anchor and every cluster aggregate, which is suspicious enough that
 * surfacing it as if it were measured would mislead the operator. This
 * detector is intentionally conservative: it only fires when the value is
 * EXACTLY 0.20 on the cluster aggregate, EXACTLY 0.20 on every anchor, and
 * the cluster has at least 2 anchors (so the chance of a real 0.20 collision
 * is negligible).
 *
 * When the backend is fixed (separate ticket) and urgency starts varying,
 * the detector will simply stop firing.
 */
export function isUrgencyPlaceholder(
  profile: ProfileLike | null | undefined,
  anchors: AnchorLike[] = [],
): boolean {
  if (!profile || profile.avg_urgency_score == null) {
    return false;
  }
  if (Math.abs(profile.avg_urgency_score - PLACEHOLDER_VALUE) > EPSILON) {
    return false;
  }
  if (anchors.length < 2) {
    return false;
  }

  return anchors.every(
    (a) => a.avg_urgency_score != null && Math.abs(a.avg_urgency_score - PLACEHOLDER_VALUE) < EPSILON,
  );
}
