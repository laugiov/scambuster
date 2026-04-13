import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThreatActorSummaryCard } from './ThreatActorSummaryCard';
import type { ThreatActorSummary } from '@/hooks/useThreatActor';
import type { ThreatActorProfile } from '@/types/threatActor';

const topActor: ThreatActorProfile = {
  name: 'Actor',
  description: '',
  sophistication: 'intermediate',
  goals: ['financial-gain'],
  primaryMotivation: 'personal-gain',
  threatActorTypes: ['criminal'],
  firstSeen: '2025-01-01T00:00:00Z',
  lastSeen: '2025-06-01T00:00:00Z',
  scamType: 'PHISHING',
  personaUsed: 'elderly_person',
  engagementHours: 10,
  engagementTurns: 5,
  iocTypeCount: 3,
  attackPattern: null,
};

const baseSummary: ThreatActorSummary = {
  conversationCount: 3,
  maxSophistication: 'intermediate',
  scamTypes: ['PHISHING', 'ROMANCE'],
  allGoals: ['financial-gain'],
  attackPatterns: ['T1566.002'],
  topActor,
};

describe('ThreatActorSummaryCard', () => {
  it('renders without crashing', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
  });

  it('displays Threat Actor header', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
    expect(screen.getByText('Threat Actor')).toBeInTheDocument();
  });

  it('displays conversation count with plural', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
    expect(screen.getByText('3 conversations')).toBeInTheDocument();
  });

  it('displays singular conversation count', () => {
    render(<ThreatActorSummaryCard summary={{ ...baseSummary, conversationCount: 1 }} />);
    expect(screen.getByText('1 conversation')).toBeInTheDocument();
  });

  it('displays sophistication badge', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
    expect(screen.getByText('intermediate')).toBeInTheDocument();
  });

  it('displays goals', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
    expect(screen.getByText('financial-gain')).toBeInTheDocument();
  });

  it('displays attack patterns', () => {
    render(<ThreatActorSummaryCard summary={baseSummary} />);
    expect(screen.getByText('T1566.002')).toBeInTheDocument();
  });

  it('hides attack patterns section when empty', () => {
    render(<ThreatActorSummaryCard summary={{ ...baseSummary, attackPatterns: [] }} />);
    expect(screen.queryByText('T1566.002')).not.toBeInTheDocument();
  });

  it('handles none sophistication level', () => {
    render(<ThreatActorSummaryCard summary={{ ...baseSummary, maxSophistication: 'none' }} />);
    expect(screen.getByText('none')).toBeInTheDocument();
  });
});
