export const ENDPOINTS = {
  auth: {
    login: '/auth/login',
    refresh: '/auth/refresh',
    logout: '/auth/logout',
  },
  monitoring: {
    autonomy: '/monitoring/autonomy',
  },
  conversations: {
    list: '/communication/conversation',
    messages: '/communication/message',
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
    allStats: '/scambaiting/stats',
  },
  campaign: {
    hunt: '/campaign/hunt',
    candidates: '/campaign/candidates',
    profile: (id: string) => `/campaign/${id}/profile`,
    messages: (id: string) => `/campaign/${id}/messages`,
    exportStix: (id: string) => `/campaign/${id}/export/stix`,
  },
  scamTypes: '/communication/scam-types',
} as const;
