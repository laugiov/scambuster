export type RecencyBucket = 'now' | 'recent' | 'stale';

/**
 * Bucket a cluster's `last_seen` timestamp into freshness categories used by
 * the list-page row indicator and the default sort order.
 *
 * Calendar-month based (not rolling 30 days) so the bucketing is intuitive
 * and matches the existing "MMM YYYY" period labels rendered next to each
 * row. A cluster whose last_seen is in the current month gets `now`; in the
 * immediately previous calendar month, `recent`; older or `null`, `stale`.
 *
 * `now` is injectable so the bucket can be exercised deterministically in
 * tests without freezing the system clock.
 */
export function bucketRecency(lastSeen: string | null | undefined, now: Date = new Date()): RecencyBucket {
  if (!lastSeen) {
    return 'stale';
  }

  const last = new Date(lastSeen);
  if (Number.isNaN(last.getTime())) {
    return 'stale';
  }

  const monthDiff = (now.getFullYear() - last.getFullYear()) * 12 + (now.getMonth() - last.getMonth());

  if (monthDiff <= 0) {
    return 'now';
  }
  if (monthDiff === 1) {
    return 'recent';
  }
  return 'stale';
}

export function recencyTooltip(bucket: RecencyBucket): string {
  switch (bucket) {
    case 'now':
      return 'Active this month';
    case 'recent':
      return 'Active last month';
    case 'stale':
      return 'Last seen 2+ months ago';
  }
}

const BUCKET_RANK: Record<RecencyBucket, number> = { now: 2, recent: 1, stale: 0 };

export function recencyRank(bucket: RecencyBucket): number {
  return BUCKET_RANK[bucket];
}
