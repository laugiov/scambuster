import { describe, it, expect } from 'vitest';
import { ENDPOINTS } from '../endpoints';

describe('ENDPOINTS', () => {
  describe('auth', () => {
    it('has correct login path', () => {
      expect(ENDPOINTS.auth.login).toBe('/auth/login');
    });

    it('has correct refresh path', () => {
      expect(ENDPOINTS.auth.refresh).toBe('/auth/refresh');
    });

    it('has correct logout path', () => {
      expect(ENDPOINTS.auth.logout).toBe('/auth/logout');
    });
  });

  describe('monitoring', () => {
    it('has correct autonomy path', () => {
      expect(ENDPOINTS.monitoring.autonomy).toBe('/monitoring/autonomy');
    });

    it('has correct llmCost path', () => {
      expect(ENDPOINTS.monitoring.llmCost).toBe('/monitoring/llm-cost');
    });

    it('has correct lifecycle path', () => {
      expect(ENDPOINTS.monitoring.lifecycle).toBe('/monitoring/conversation-lifecycle');
    });

    it('has correct rateLimits path', () => {
      expect(ENDPOINTS.monitoring.rateLimits).toBe('/monitoring/rate-limits');
    });

    it('has correct pipelineTraceDetail function', () => {
      expect(ENDPOINTS.monitoring.pipelineTraceDetail('msg-123')).toBe('/monitoring/pipeline-traces/msg-123');
    });

    it('has analytics paths', () => {
      expect(ENDPOINTS.monitoring.analyticsIocTimeline).toBe('/monitoring/analytics/ioc-timeline');
      expect(ENDPOINTS.monitoring.analyticsConversationTimeline).toBe('/monitoring/analytics/conversation-timeline');
      expect(ENDPOINTS.monitoring.analyticsIocDistribution).toBe('/monitoring/analytics/ioc-distribution');
      expect(ENDPOINTS.monitoring.analyticsScamDistribution).toBe('/monitoring/analytics/scam-distribution');
      expect(ENDPOINTS.monitoring.analyticsCostTimeline).toBe('/monitoring/analytics/cost-timeline');
      expect(ENDPOINTS.monitoring.analyticsPipelineTimeline).toBe('/monitoring/analytics/pipeline-timeline');
      expect(ENDPOINTS.monitoring.analyticsActivityFeed).toBe('/monitoring/analytics/activity-feed');
      expect(ENDPOINTS.monitoring.analyticsWeeklyTrends).toBe('/monitoring/analytics/weekly-trends');
    });
  });

  describe('conversations', () => {
    it('has correct list path', () => {
      expect(ENDPOINTS.conversations.list).toBe('/communication/conversation');
    });

    it('has correct detail function', () => {
      expect(ENDPOINTS.conversations.detail('abc')).toBe('/communication/conversation/abc');
    });

    it('has correct messages function', () => {
      expect(ENDPOINTS.conversations.messages('abc')).toBe('/communication/conversation/abc/messages');
    });

    it('has correct iocs function', () => {
      expect(ENDPOINTS.conversations.iocs('abc')).toBe('/communication/conversation/abc/iocs');
    });

    it('has correct exportStix function', () => {
      expect(ENDPOINTS.conversations.exportStix('abc')).toBe('/conversations/abc/export/stix');
    });
  });

  describe('iocs', () => {
    it('has correct list path', () => {
      expect(ENDPOINTS.iocs.list).toBe('/iocs');
    });

    it('has correct detail function', () => {
      expect(ENDPOINTS.iocs.detail('ind-1')).toBe('/iocs/ind-1/detail');
    });

    it('has correct coOccurrence path', () => {
      expect(ENDPOINTS.iocs.coOccurrence).toBe('/iocs/co-occurrence');
    });

    it('has correct context function', () => {
      expect(ENDPOINTS.iocs.context('ind-1')).toBe('/iocs/ind-1/context');
    });

    it('has correct exportStix path', () => {
      expect(ENDPOINTS.iocs.exportStix).toBe('/iocs/export/stix');
    });
  });

  describe('scambaiting', () => {
    it('has correct stats path', () => {
      expect(ENDPOINTS.scambaiting.stats).toBe('/scambaiting/stats');
    });

    it('has correct statsByType function', () => {
      expect(ENDPOINTS.scambaiting.statsByType('PHISHING')).toBe('/scambaiting/stats/PHISHING');
    });

    it('has correct personaPerformance function', () => {
      expect(ENDPOINTS.scambaiting.personaPerformance('elderly_person')).toBe('/scambaiting/persona/elderly_person/performance');
    });

    it('has correct closeConversation function', () => {
      expect(ENDPOINTS.scambaiting.closeConversation('conv-1')).toBe('/scambaiting/conversation/conv-1/close');
    });
  });

  describe('campaign', () => {
    it('has correct hunt path', () => {
      expect(ENDPOINTS.campaign.hunt).toBe('/campaign/hunt');
    });

    it('has correct candidates path', () => {
      expect(ENDPOINTS.campaign.candidates).toBe('/campaign/candidates');
    });

    it('has correct detail function', () => {
      expect(ENDPOINTS.campaign.detail('c1')).toBe('/campaign/c1/detail');
    });

    it('has correct profile function', () => {
      expect(ENDPOINTS.campaign.profile('c1')).toBe('/campaign/c1/profile');
    });

    it('has correct messages function', () => {
      expect(ENDPOINTS.campaign.messages('c1')).toBe('/campaign/c1/messages');
    });

    it('has correct exportStix function', () => {
      expect(ENDPOINTS.campaign.exportStix('c1')).toBe('/campaign/c1/export/stix');
    });

    it('has correct promoteRule function', () => {
      expect(ENDPOINTS.campaign.promoteRule('r1')).toBe('/campaign/rule/r1/promote');
    });
  });

  describe('personas', () => {
    it('has correct detail function', () => {
      expect(ENDPOINTS.personas.detail('elderly_person')).toBe('/personas/elderly_person');
    });

    it('has correct update function', () => {
      expect(ENDPOINTS.personas.update('elderly_person')).toBe('/personas/elderly_person');
    });

    it('has correct toggleActive function', () => {
      expect(ENDPOINTS.personas.toggleActive('elderly_person')).toBe('/personas/elderly_person/active');
    });
  });

  describe('clusters', () => {
    it('has correct list path', () => {
      expect(ENDPOINTS.clusters.list).toBe('/clusters');
    });

    it('has correct stats path', () => {
      expect(ENDPOINTS.clusters.stats).toBe('/clusters/stats');
    });

    it('has correct detail function', () => {
      expect(ENDPOINTS.clusters.detail('cl-1')).toBe('/clusters/cl-1');
    });

    it('has correct exportStix function', () => {
      expect(ENDPOINTS.clusters.exportStix('cl-1')).toBe('/clusters/cl-1/export/stix');
    });

    it('has correct forIoc function', () => {
      expect(ENDPOINTS.clusters.forIoc('ind-1')).toBe('/iocs/ind-1/cluster');
    });
  });

  describe('impact', () => {
    it('has correct summary path', () => {
      expect(ENDPOINTS.impact.summary).toBe('/impact/summary');
    });

    it('has correct iocUniqueness path', () => {
      expect(ENDPOINTS.impact.iocUniqueness).toBe('/impact/ioc-uniqueness');
    });
  });

  describe('other', () => {
    it('has correct scamTypes path', () => {
      expect(ENDPOINTS.scamTypes).toBe('/communication/scam-types');
    });

    it('has correct meta config path', () => {
      expect(ENDPOINTS.meta.config).toBe('/meta/config');
    });

    it('has correct admin llmKillSwitch path', () => {
      expect(ENDPOINTS.admin.llmKillSwitch).toBe('/admin/llm/killswitch');
    });
  });
});
