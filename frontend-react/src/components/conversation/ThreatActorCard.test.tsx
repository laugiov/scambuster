import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ThreatActorCard } from './ThreatActorCard';
import type { ThreatActorProfile } from '@/types/threatActor';

const baseProfile: ThreatActorProfile = {
  name: 'Scammer A',
  description: 'A phishing actor targeting banks',
  sophistication: 'intermediate',
  goals: ['financial-gain', 'credential-theft'],
  primaryMotivation: 'personal-gain',
  threatActorTypes: ['criminal'],
  firstSeen: '2025-01-01T00:00:00Z',
  lastSeen: '2025-06-01T00:00:00Z',
  scamType: 'PHISHING',
  personaUsed: 'elderly_person',
  engagementHours: 48,
  engagementTurns: 12,
  iocTypeCount: 5,
  attackPattern: {
    name: 'Spearphishing Link',
    techniqueId: 'T1566.002',
    url: 'https://attack.mitre.org/techniques/T1566/002/',
  },
};

describe('ThreatActorCard', () => {
  it('renders without crashing', () => {
    render(<ThreatActorCard profile={baseProfile} />);
  });

  it('displays Threat Actor header', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('Threat Actor')).toBeInTheDocument();
  });

  it('displays sophistication badge', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('intermediate')).toBeInTheDocument();
  });

  it('displays goals', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('financial-gain')).toBeInTheDocument();
    expect(screen.getByText('credential-theft')).toBeInTheDocument();
  });

  it('displays primary motivation', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('personal-gain')).toBeInTheDocument();
  });

  it('displays MITRE ATT&CK pattern', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('[T1566.002] Spearphishing Link')).toBeInTheDocument();
  });

  it('displays description', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('A phishing actor targeting banks')).toBeInTheDocument();
  });

  it('displays engagement metrics', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('2.0d / 12 exchanges')).toBeInTheDocument();
    expect(screen.getByText('5 types')).toBeInTheDocument();
  });

  it('displays persona label when provided', () => {
    render(<ThreatActorCard profile={baseProfile} personaLabel="Elderly Person" />);
    expect(screen.getByText('Elderly Person')).toBeInTheDocument();
  });

  it('displays persona code when label not provided', () => {
    render(<ThreatActorCard profile={baseProfile} />);
    expect(screen.getByText('elderly_person')).toBeInTheDocument();
  });

  it('collapses content when header is clicked', async () => {
    render(<ThreatActorCard profile={baseProfile} />);
    const user = userEvent.setup();
    // Initially expanded, description should be visible
    expect(screen.getByText('A phishing actor targeting banks')).toBeInTheDocument();
    // Click to collapse
    await user.click(screen.getByText('Threat Actor'));
    expect(screen.queryByText('A phishing actor targeting banks')).not.toBeInTheDocument();
  });

  it('handles profile without attackPattern', () => {
    const profile = { ...baseProfile, attackPattern: null };
    render(<ThreatActorCard profile={profile} />);
    expect(screen.queryByText(/T1566/)).not.toBeInTheDocument();
  });

  it('handles profile without description', () => {
    const profile = { ...baseProfile, description: '' };
    render(<ThreatActorCard profile={profile} />);
    expect(screen.getByText('Threat Actor')).toBeInTheDocument();
  });

  it('formats hours under 1h as minutes', () => {
    const profile = { ...baseProfile, engagementHours: 0.5 };
    render(<ThreatActorCard profile={profile} />);
    expect(screen.getByText('30min / 12 exchanges')).toBeInTheDocument();
  });

  it('formats hours under 24h with decimal', () => {
    const profile = { ...baseProfile, engagementHours: 5.5 };
    render(<ThreatActorCard profile={profile} />);
    expect(screen.getByText('5.5h / 12 exchanges')).toBeInTheDocument();
  });
});
