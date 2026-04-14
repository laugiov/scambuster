import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Settings } from './Settings';
import { mockMetaConfig as baseMockMetaConfig, mockStats as baseMockStats } from '@/__tests__/fixtures';

const BASE = '/api/v1';

const mockStats = {
  ...baseMockStats,
  convergence: { ...baseMockStats.convergence, converged_types: 1 },
};

const mockMetaConfig = {
  ...baseMockMetaConfig,
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
  scam_types: [],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('Settings', () => {
  it('renders the settings page with title and system status', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Settings/i)).toBeInTheDocument();
      expect(screen.getByText(/System Status/i)).toBeInTheDocument();
    });
  });

  it('renders system status section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/System Status/i)).toBeInTheDocument();
    });
  });

  it('shows operational pipeline status', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Operational/i)).toBeInTheDocument();
    });
  });

  it('renders counter section with stats', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('15')).toBeInTheDocument(); // total conversations
      expect(screen.getByText('42')).toBeInTheDocument(); // total messages
      expect(screen.getByText('89')).toBeInTheDocument(); // total iocs
    });
  });

  it('renders platform info section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Platform Info/i)).toBeInTheDocument();
      expect(screen.getByText(/Symfony 7/i)).toBeInTheDocument();
      expect(screen.getByText(/PostgreSQL 15/i)).toBeInTheDocument();
    });
  });

  it('renders agents section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Orchestrator')).toBeInTheDocument();
      expect(screen.getByText('PolicyGuard')).toBeInTheDocument();
    });
  });

  it('shows LLM provider from config', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/openai.*gpt-4o-mini/i)).toBeInTheDocument();
    });
  });
});
