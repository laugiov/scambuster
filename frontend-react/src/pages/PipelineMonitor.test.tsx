import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import PipelineMonitor from './PipelineMonitor';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('PipelineMonitor', () => {
  it('mounts without crashing', () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { container } = render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <PipelineMonitor />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(container).toBeTruthy();
  });
});
