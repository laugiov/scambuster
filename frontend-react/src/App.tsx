import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppLayout } from '@/components/layout/AppLayout';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorBoundary } from '@/components/feedback/ErrorBoundary';
import { Login } from '@/pages/Login';

const Dashboard = lazy(() => import('@/pages/Dashboard'));
const Conversations = lazy(() => import('@/pages/Conversations'));
const ConversationDetail = lazy(() => import('@/pages/ConversationDetail'));
const IocExplorer = lazy(() => import('@/pages/IocExplorer'));
const StixExport = lazy(() => import('@/pages/StixExport'));
const Personas = lazy(() => import('@/pages/Personas'));
const Campaigns = lazy(() => import('@/pages/Campaigns'));
const Settings = lazy(() => import('@/pages/Settings'));

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
});

export default function App() {
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <Suspense fallback={<Loading message="Loading page..." />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route
              element={
                <AuthGuard>
                  <AppLayout />
                </AuthGuard>
              }
            >
              <Route index element={<Dashboard />} />
              <Route path="conversations" element={<Conversations />} />
              <Route path="conversations/:id" element={<ConversationDetail />} />
              <Route path="ioc-explorer" element={<IocExplorer />} />
              <Route path="stix-export" element={<StixExport />} />
              <Route path="personas" element={<Personas />} />
              <Route path="campaigns" element={<Campaigns />} />
              <Route path="settings" element={<Settings />} />
            </Route>
          </Routes>
          </Suspense>
        </BrowserRouter>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
