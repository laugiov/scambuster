import { NavLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuthStore } from '@/store/authStore';
import { LanguageSwitcher } from '@/components/ui/LanguageSwitcher';

interface NavItem {
  labelKey: string;
  path: string;
  icon: string;
}

const NAV_ITEMS: NavItem[] = [
  { labelKey: 'nav.dashboard', path: '/', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  { labelKey: 'nav.campaigns', path: '/campaigns', icon: 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
  { labelKey: 'nav.conversations', path: '/conversations', icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
  { labelKey: 'nav.iocExplorer', path: '/ioc-explorer', icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z' },
  { labelKey: 'nav.stixExport', path: '/stix-export', icon: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4' },
  { labelKey: 'nav.personas', path: '/personas', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
  { labelKey: 'nav.llmCosts', path: '/llm-costs', icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
  { labelKey: 'nav.settings', path: '/settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
];

function NavIcon({ path }: { path: string }) {
  return (
    <svg className="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" d={path} />
    </svg>
  );
}

export function Sidebar() {
  const { t } = useTranslation();
  const logout = useAuthStore((s) => s.logout);

  return (
    <aside className="fixed top-0 left-0 h-screen w-[var(--spacing-sidebar)] bg-sidebar flex flex-col z-50" role="navigation" aria-label="Main navigation">
      <div className="px-5 py-6">
        <h1 className="text-lg font-semibold text-on-surface tracking-wide">ScamBuster</h1>
        <p className="text-xs text-on-surface-dim uppercase tracking-widest mt-0.5">{t('nav.subtitle')}</p>
      </div>

      <nav className="flex-1 px-3 space-y-0.5" aria-label="Pages">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end={item.path === '/'}
            className={({ isActive }) =>
              `flex items-center gap-3 px-3 py-2.5 rounded-md text-sm transition-colors ${
                isActive
                  ? 'bg-sidebar-active text-accent font-medium'
                  : 'text-on-surface-variant hover:bg-sidebar-hover hover:text-on-surface'
              }`
            }
          >
            <NavIcon path={item.icon} />
            {t(item.labelKey)}
          </NavLink>
        ))}
      </nav>

      <div className="px-3 pb-4 space-y-0.5">
        <div className="flex justify-center mb-2">
          <LanguageSwitcher />
        </div>
        <button
          onClick={() => void logout()}
          className="flex items-center gap-3 px-3 py-2.5 rounded-md text-sm text-on-surface-variant hover:bg-sidebar-hover hover:text-error w-full transition-colors cursor-pointer"
        >
          <NavIcon path="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          {t('nav.logout')}
        </button>
      </div>
    </aside>
  );
}
