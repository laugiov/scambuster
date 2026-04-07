export interface ThreatActorProfile {
  name: string;
  description: string;
  sophistication: 'none' | 'minimal' | 'intermediate' | 'advanced';
  goals: string[];
  primaryMotivation: string;
  threatActorTypes: string[];
  firstSeen: string;
  lastSeen: string;
  scamType: string;
  personaUsed: string;
  engagementHours: number;
  engagementTurns: number;
  iocTypeCount: number;
  attackPattern: AttackPatternInfo | null;
}

export interface AttackPatternInfo {
  name: string;
  techniqueId: string;
  url: string;
}
