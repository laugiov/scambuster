import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PsychProfilePanel } from './PsychProfilePanel';
import type { ThreatActorPsychProfile } from '@/types/threatActor';

const { mockUse } = vi.hoisted(() => ({ mockUse: vi.fn() }));

vi.mock('@/hooks/useClusterPsychProfile', () => ({
  useClusterPsychProfile: (id: string) => mockUse(id),
}));

const profile: ThreatActorPsychProfile = {
  cluster_id: 'c-1',
  dominant_lever: 'Urgency',
  secondary_levers: ['Authority', 'Scarcity'],
  behavioural_summary: 'Escalates deadlines and cites a fake bank title.',
  escalation_pattern: 'rapid',
  victim_targeting: 'Time-poor account holders.',
  dominant_stimulus: 'fear',
  avg_urgency: 0.72,
  hesitation_events: 2,
  language_switches: 1,
  conversation_count: 3,
  message_count: 20,
  generated_by_model: 'gpt-4o-mini',
  prompt_version: 'v1',
  generated_at: '2026-07-06T10:00:00+00:00',
};

describe('PsychProfilePanel', () => {
  beforeEach(() => mockUse.mockReset());

  it('renders the dominant lever, secondary levers and behavioural summary', () => {
    mockUse.mockReturnValue({ data: profile, isLoading: false });
    render(<PsychProfilePanel clusterId="c-1" />);

    expect(screen.getByTestId('psych-dominant-lever').textContent).toBe('Urgency');
    expect(screen.getByText('Authority')).toBeTruthy();
    expect(screen.getByText('Scarcity')).toBeTruthy();
    expect(screen.getByText(/Escalates deadlines/)).toBeTruthy();
    expect(screen.getByText('0.72')).toBeTruthy();
  });

  it('renders an empty state when the cluster has no profile', () => {
    mockUse.mockReturnValue({ data: null, isLoading: false });
    render(<PsychProfilePanel clusterId="c-1" />);

    expect(screen.getByTestId('psych-profile-empty')).toBeTruthy();
    expect(screen.queryByTestId('psych-profile')).toBeNull();
  });

  it('renders nothing while loading', () => {
    mockUse.mockReturnValue({ data: undefined, isLoading: true });
    const { container } = render(<PsychProfilePanel clusterId="c-1" />);

    expect(container.firstChild).toBeNull();
  });
});
