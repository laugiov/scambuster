import { useTranslation } from 'react-i18next';

export function Loading({ message }: { message?: string }) {
  const { t } = useTranslation();

  return (
    <div className="flex items-center justify-center py-12">
      <div className="flex items-center gap-3 text-on-surface-dim">
        <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <span className="text-sm">{message ?? t('common.loading')}</span>
      </div>
    </div>
  );
}
