import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { TheaterIocCard } from './TheaterIocCard';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import type { TheaterIoc } from '@/hooks/useTheaterReplay';

function baseIoc(overrides: Partial<TheaterIoc> = {}): TheaterIoc {
  return {
    indicator_id: '11111111-1111-1111-1111-111111111111',
    type: 'url',
    value: 'https://example.com',
    value_norm: 'https[://]example[.]com',
    category: 'infrastructure',
    msg_id: '22222222-2222-2222-2222-222222222222',
    msg_idx: 0,
    revelation_context: undefined,
    ...overrides,
  };
}

function renderCard(ioc: TheaterIoc) {
  // UrgencyBar now calls useUrgencyCorpusStats
  // (react-query) so tests must provide a QueryClient. retry: false
  // and a fresh client per render keep tests deterministic — the
  // network call falls through to the queryFn, which throws on the
  // mocked-absent endpoint, the hook returns { data: undefined }, and
  // the bar renders without the corpus median tick (degraded but
  // valid). That matches production behaviour when the stats endpoint
  // is offline.
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MaskModeProvider>
        <TheaterIocCard ioc={ioc} />
      </MaskModeProvider>
    </QueryClientProvider>,
  );
}

describe('TheaterIocCard — confidence-gated role display', () => {
  it('hides Role: label when enrichment_confidence < 0.7', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.5,
          semantic_role: 'PHISHING_CREDENTIAL_URL',
          context_excerpt: null,
        },
      }),
    );
    expect(screen.queryByText(/PHISHING_CREDENTIAL_URL/i)).toBeNull();
  });

  it('hides Role: label exactly at the 0.7 boundary (strict less-than)', () => {
    // 0.6999 → hidden ; 0.7 → shown
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.69,
          semantic_role: 'CONTACT_CHANNEL',
          context_excerpt: null,
        },
      }),
    );
    expect(screen.queryByText(/CONTACT_CHANNEL/i)).toBeNull();
  });

  it('shows Role: label with confidence percentage when confidence >= 0.7', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.85,
          semantic_role: 'PAYMENT_DESTINATION',
          context_excerpt: null,
        },
      }),
    );
    expect(screen.getByText(/PAYMENT_DESTINATION/i)).toBeTruthy();
    expect(screen.getByText(/85%/)).toBeTruthy();
  });

  it('does not render Role: block when context is not enriched', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'pending',
          enrichment_confidence: 0.9,
          semantic_role: 'PAYMENT_DESTINATION',
          context_excerpt: null,
        },
      }),
    );
    expect(screen.queryByText(/PAYMENT_DESTINATION/i)).toBeNull();
  });

  // urgency_score + context_excerpt now ALSO gated by
  // the same ≥0.7 confidence threshold (was only the Role label
  // before). Replaces the earlier test that asserted the
  // opposite behaviour.
  it('hides urgency bar AND excerpt when confidence < 0.7', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.55,
          semantic_role: 'PHISHING_CREDENTIAL_URL',
          context_excerpt: 'scammer dropped a portfolio link mid-thread',
          urgency_score: 0.4,
        },
      }),
    );
    expect(screen.queryByText(/PHISHING_CREDENTIAL_URL/i)).toBeNull();
    expect(screen.queryByText(/portfolio link mid-thread/i)).toBeNull();
    expect(screen.queryByText(/Scammer urgency/i)).toBeNull();
  });

  it('renders urgency bar AND excerpt when confidence ≥ 0.7', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.85,
          semantic_role: 'PAYMENT_DESTINATION',
          context_excerpt: 'scammer dropped a portfolio link mid-thread',
          urgency_score: 0.4,
        },
      }),
    );
    expect(screen.getByText(/portfolio link mid-thread/i)).toBeTruthy();
    expect(screen.getByText(/Scammer urgency/i)).toBeTruthy();
  });

  // Kept under the original test name so the assertion library still
  // surfaces a useful failure should this regress.
  it('still renders other footprint fields (stimulus, hesitation, co-revealed) when role is hidden', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.55,
          semantic_role: 'PHISHING_CREDENTIAL_URL',
          context_excerpt: 'scammer dropped a portfolio link mid-thread',
          urgency_score: 0.4,
          stimulus_type: 'DIRECT_REQUEST',
          hesitation_detected: true,
        },
      }),
    );
    expect(screen.queryByText(/PHISHING_CREDENTIAL_URL/i)).toBeNull();
    expect(screen.queryByText(/portfolio link mid-thread/i)).toBeNull(); // excerpt hidden under S2
    // stimulus + hesitation badges still rendered (deterministic-ish, not LLM narrative)
    expect(screen.getByText(/Active stimulus/i)).toBeTruthy();
    expect(screen.getByText(/hesitation/i)).toBeTruthy();
  });
});

describe('TheaterIocCard — active-stimulus badge condition', () => {
  // The badge shows for any stimulus NOT in (PASSIVE, UNKNOWN) — an
  // exclusion over the real 7-value backend enum, not the legacy
  // 'active'/'passive' pair.
  it.each(['DIRECT_REQUEST', 'DOCUMENT_REQUEST', 'PAYMENT_INITIATION', 'TRUST_BUILDING', 'URGENCY_PRESSURE'] as const)(
    'shows the badge for %s',
    (stimulus) => {
      renderCard(
        baseIoc({
          revelation_context: {
            enrichment_status: 'enriched',
            enrichment_confidence: 0.9,
            stimulus_type: stimulus,
          },
        }),
      );
      expect(screen.getByText(/Active stimulus/i)).toBeTruthy();
    },
  );

  it.each(['PASSIVE', 'UNKNOWN'] as const)('hides the badge for %s', (stimulus) => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.9,
          stimulus_type: stimulus,
        },
      }),
    );
    expect(screen.queryByText(/Active stimulus/i)).toBeNull();
  });

  it('hides the badge when the stimulus is absent', () => {
    renderCard(
      baseIoc({
        revelation_context: {
          enrichment_status: 'enriched',
          enrichment_confidence: 0.9,
          stimulus_type: null,
        },
      }),
    );
    expect(screen.queryByText(/Active stimulus/i)).toBeNull();
  });
});
