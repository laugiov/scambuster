import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AppLayout } from '@/components/layout/AppLayout';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { Login } from '@/pages/Login';
import { Dashboard } from '@/pages/Dashboard';
import { Conversations } from '@/pages/Conversations';
import { ConversationDetail } from '@/pages/ConversationDetail';
import { IocExplorer } from '@/pages/IocExplorer';
import { StixExport } from '@/pages/StixExport';
import { Personas } from '@/pages/Personas';
import { Campaigns } from '@/pages/Campaigns';
import { Settings } from '@/pages/Settings';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
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
      </BrowserRouter>
    </QueryClientProvider>
  );
}
