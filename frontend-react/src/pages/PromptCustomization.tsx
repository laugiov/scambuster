import { useTranslation } from 'react-i18next';
import { usePromptOverrides } from '@/hooks/usePromptOverrides';
import { PromptOverrideCard } from '@/components/promptOverrides/PromptOverrideCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

export function PromptCustomization() {
  const { t } = useTranslation();
  const { data: rows, isLoading, error, refetch } = usePromptOverrides();

  if (isLoading) return <Loading message={t('promptCustomization.loading', 'Loading prompts...')} />;
  if (error) {
    return (
      <ErrorMessage
        message={t('promptCustomization.failedLoad', 'Failed to load prompts')}
        onRetry={() => void refetch()}
      />
    );
  }

  const list = rows ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-medium text-on-surface">
          {t('promptCustomization.title', 'Prompt Customization')}
        </h1>
        <p className="text-sm text-on-surface-dim">
          {t(
            'promptCustomization.subtitle',
            'Customize the generative prompts for your context. Overrides never relax the safety guardrails.',
          )}
        </p>
      </div>

      {list.length === 0 ? (
        <p className="text-sm text-on-surface-dim">{t('promptCustomization.empty', 'No overridable prompts.')}</p>
      ) : (
        <div className="space-y-3">
          {list.map((row) => (
            <PromptOverrideCard key={row.key} row={row} />
          ))}
        </div>
      )}
    </div>
  );
}

export default PromptCustomization;
