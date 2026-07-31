import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useThreatActorProfile, useThreatActorSummary } from '../useThreatActor';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

const mockStixBundle = {
  type: 'bundle',
  objects: [
    {
      type: 'threat-actor',
      id: 'threat-actor--abc',
      name: 'Scammer Alpha',
      description: 'A phishing actor',
      sophistication: 'intermediate',
      goals: ['steal credentials'],
      primary_motivation: 'personal-financial-gain',
      threat_actor_types: ['criminal'],
      first_seen: '2026-01-01T00:00:00Z',
      last_seen: '2026-03-01T00:00:00Z',
      extensions: {
        // STIX 2.1 conformant shape: keyed by the ext-def id, property-extension.
        'extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01': {
          extension_type: 'property-extension',
          x_scambuster_actor: {
            scam_type: 'PHISHING',
            style_dna: { persona_used: 'elderly_person', engagement_turns: 8 },
            infra_dna: { engagement_hours: 2.5, ioc_type_count: 4 },
          },
        },
      },
    },
    {
      type: 'attack-pattern',
      id: 'attack-pattern--def',
      name: 'Phishing',
      external_references: [
        { source_name: 'mitre-attack', external_id: 'T1566.002', url: 'https://attack.mitre.org/techniques/T1566/002/' },
      ],
    },
  ],
};

describe('useThreatActorProfile', () => {
  it('extracts profile from STIX bundle', async () => {
    server.use(
      http.get(`${BASE}/conversations/conv-1/export/stix`, () =>
        HttpResponse.json(mockStixBundle),
      ),
    );

    const { result } = renderHook(() => useThreatActorProfile('conv-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const profile = result.current.data!;
    expect(profile.name).toBe('Scammer Alpha');
    expect(profile.sophistication).toBe('intermediate');
    expect(profile.scamType).toBe('PHISHING');
    expect(profile.personaUsed).toBe('elderly_person');
    expect(profile.engagementHours).toBe(2.5);
    expect(profile.engagementTurns).toBe(8);
    expect(profile.iocTypeCount).toBe(4);
    expect(profile.attackPattern).not.toBeNull();
    expect(profile.attackPattern!.techniqueId).toBe('T1566.002');
    // A single-conversation actor is NOT a cluster — cluster fields stay empty.
    expect(profile.clusterType).toBeNull();
    expect(profile.conversationCount).toBe(0);
    expect(profile.anchorIocTypes).toEqual([]);
  });

  it('extracts cluster fields from a consolidated (clustered) actor bundle', async () => {
    const clusterBundle = {
      type: 'bundle',
      objects: [
        {
          type: 'threat-actor',
          id: 'threat-actor--cluster',
          name: 'Consolidated Actor',
          sophistication: 'minimal',
          extensions: {
            'extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01': {
              extension_type: 'property-extension',
              x_scambuster_actor: {
                cluster_type: 'consolidated',
                conversation_count: 2,
                anchor_ioc_types: ['phone', 'iban'],
              },
            },
          },
        },
      ],
    };
    server.use(
      http.get(`${BASE}/conversations/conv-clu/export/stix`, () => HttpResponse.json(clusterBundle)),
    );

    const { result } = renderHook(() => useThreatActorProfile('conv-clu'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const profile = result.current.data!;
    expect(profile.clusterType).toBe('consolidated');
    expect(profile.conversationCount).toBe(2);
    expect(profile.anchorIocTypes).toEqual(['phone', 'iban']);
    // Clustered actors carry no per-session engagement — must NOT be fabricated.
    expect(profile.engagementHours).toBe(0);
    expect(profile.iocTypeCount).toBe(0);
  });

  it('returns null when no threat-actor in bundle', async () => {
    server.use(
      http.get(`${BASE}/conversations/conv-2/export/stix`, () =>
        HttpResponse.json({ type: 'bundle', objects: [] }),
      ),
    );

    const { result } = renderHook(() => useThreatActorProfile('conv-2'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when convId is empty', () => {
    const { result } = renderHook(() => useThreatActorProfile(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useThreatActorSummary', () => {
  it('aggregates profiles from multiple conversations', async () => {
    server.use(
      http.get(`${BASE}/conversations/:id/export/stix`, () =>
        HttpResponse.json(mockStixBundle),
      ),
    );

    const { result } = renderHook(
      () => useThreatActorSummary(['conv-1', 'conv-2']),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const summary = result.current.data!;
    expect(summary.conversationCount).toBe(2);
    expect(summary.scamTypes).toContain('PHISHING');
    expect(summary.maxSophistication).toBe('intermediate');
    expect(summary.allGoals).toContain('steal credentials');
    expect(summary.attackPatterns).toContain('Phishing');
  });

  it('returns null when all exports fail', async () => {
    server.use(
      http.get(`${BASE}/conversations/:id/export/stix`, () =>
        HttpResponse.json({}, { status: 500 }),
      ),
    );

    const { result } = renderHook(
      () => useThreatActorSummary(['conv-1']),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when convIds is empty', () => {
    const { result } = renderHook(
      () => useThreatActorSummary([]),
      { wrapper: createWrapper() },
    );
    expect(result.current.fetchStatus).toBe('idle');
  });

  it('caps at 5 conversations', async () => {
    let callCount = 0;
    server.use(
      http.get(`${BASE}/conversations/:id/export/stix`, () => {
        callCount++;
        return HttpResponse.json(mockStixBundle);
      }),
    );

    const ids = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'];
    const { result } = renderHook(
      () => useThreatActorSummary(ids),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(callCount).toBeLessThanOrEqual(5);
  });

  it('deduplicates conversation IDs', async () => {
    let callCount = 0;
    server.use(
      http.get(`${BASE}/conversations/:id/export/stix`, () => {
        callCount++;
        return HttpResponse.json(mockStixBundle);
      }),
    );

    const { result } = renderHook(
      () => useThreatActorSummary(['conv-1', 'conv-1', 'conv-1']),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(callCount).toBe(1);
  });
});
