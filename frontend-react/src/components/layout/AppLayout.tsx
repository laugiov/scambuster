import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';

export function AppLayout() {
  return (
    <div className="min-h-screen bg-surface-base">
      <Sidebar />
      <main className="ml-[var(--spacing-sidebar)] p-8">
        <Outlet />
      </main>
    </div>
  );
}
