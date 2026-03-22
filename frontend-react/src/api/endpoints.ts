export const ENDPOINTS = {
  auth: {
    login: '/auth/login',
    refresh: '/auth/refresh',
    logout: '/auth/logout',
  },
  monitoring: {
    autonomy: '/monitoring/autonomy',
    llmCost: '/monitoring/llm-cost',
  },
  conversations: {
    list: '/communication/conversation',
    detail: (id: string) => `/communication/conversation/${id}`,
    messages: (id: string) => `/communication/conversation/${id}/messages`,
    iocs: (id: string) => `/communication/conversation/${id}/iocs`,
  },
  iocs: {
    list: '/iocs',
  },
  scambaiting: {
    stats: '/scambaiting/stats',
    statsByType: (code: string) => `/scambaiting/stats/${code}`,
    personaPerformance: (code: string) => `/scambaiting/persona/${code}/performance`,
    selectPersona: '/scambaiting/select-persona',
    closeConversation: (id: string) => `/scambaiting/conversation/${id}/close`,
  },
  campaign: {
    hunt: '/campaign/hunt',
    candidates: '/campaign/candidates',
    profile: (id: string) => `/campaign/${id}/profile`,
    messages: (id: string) => `/campaign/${id}/messages`,
    exportStix: (id: string) => `/campaign/${id}/export/stix`,
  },
  scamTypes: '/communication/scam-types',
  meta: {
    config: '/meta/config',
  },
} as const;
