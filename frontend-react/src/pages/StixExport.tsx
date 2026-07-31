import { useTranslation } from 'react-i18next';
import { useCampaignCandidates, useStixExport } from '@/hooks/useStix';
import { useAutonomyStats } from '@/hooks/useStats';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

export function StixExport() {
  const { t } = useTranslation();
  const { data: candidates, isLoading, error, refetch } = useCampaignCandidates();
  const { data: stats } = useAutonomyStats();
  const exportMutation = useStixExport();

  if (isLoading) return <Loading message={t('stixExport.loading')} />;
  if (error) return <ErrorMessage message={t('stixExport.failedLoad')} onRetry={() => void refetch()} />;

  const safeCandidates = candidates ?? [];
  const bundleJson = exportMutation.data?.bundle
    ? JSON.stringify(exportMutation.data.bundle, null, 2)
    : null;
  const indicatorCount = exportMutation.data?.bundle
    ? ((exportMutation.data.bundle as { objects?: unknown[] }).objects ?? []).filter(
        (o) => (o as { type?: string }).type === 'indicator',
      ).length
    : 0;

  function handleExport(campaignId: string) {
    exportMutation.mutate(campaignId);
  }

  function handleDownload() {
    if (!bundleJson || !exportMutation.data) return;
    const blob = new Blob([bundleJson], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `stix-bundle-${exportMutation.data.bundle_id.slice(9, 17)}.json`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('stixExport.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('stixExport.subtitle')}</p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label={t('stixExport.exportableIocs')} value={stats?.iocs.total ?? 0} />
        <StatCard label={t('stixExport.campaignsAvailable')} value={safeCandidates.length} />
        <StatCard
          label={t('stixExport.indicatorsExported')}
          value={indicatorCount > 0 ? indicatorCount : '--'}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left: Campaign list */}
        <div className="bg-surface-low rounded-lg p-6 space-y-4">
          <h2 className="text-base font-medium text-on-surface">{t('stixExport.selectCampaign')}</h2>

          {safeCandidates.length === 0 ? (
            <p className="text-sm text-on-surface-dim py-8 text-center">
              {t('stixExport.noCampaigns')}
            </p>
          ) : (
            <div className="space-y-3">
              {safeCandidates.map((c) => (
                <div
                  key={c.campaign_id}
                  className="flex items-center justify-between bg-surface-base rounded-lg px-4 py-3"
                >
                  <div>
                    <span className="font-mono text-xs text-accent">{c.campaign_id.slice(0, 8)}</span>
                    <div className="flex items-center gap-3 mt-1 text-xs text-on-surface-dim">
                      <span>PPV: {(c.ppv * 100).toFixed(0)}%</span>
                      <span>{c.hits_total} hits</span>
                    </div>
                  </div>
                  <button
                    onClick={() => handleExport(c.campaign_id)}
                    disabled={exportMutation.isPending}
                    className="bg-accent-muted hover:bg-accent-hover text-on-surface text-xs font-medium rounded px-4 py-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                  >
                    {exportMutation.isPending ? t('stixExport.generating') : t('stixExport.export')}
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Right: Bundle Preview */}
        <div className="bg-surface-low rounded-lg p-6 flex flex-col">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-error" />
              <span className="w-2.5 h-2.5 rounded-full bg-warning" />
              <span className="w-2.5 h-2.5 rounded-full bg-success" />
              <span className="text-sm font-medium text-on-surface ml-2">{t('stixExport.bundlePreview')}</span>
            </div>
            {bundleJson && (
              <button
                onClick={handleDownload}
                className="text-xs text-accent hover:text-accent-hover font-medium transition-colors cursor-pointer"
              >
                {t('stixExport.download')}
              </button>
            )}
          </div>

          <pre className="flex-1 min-h-[300px] p-4 bg-surface-base rounded-lg font-mono text-xs text-accent/70 overflow-auto">
            {bundleJson ?? t('stixExport.previewPlaceholder')}
          </pre>

          {exportMutation.error && (
            <p className="mt-3 text-sm text-error bg-error/10 rounded px-3 py-2" role="alert">
              {t('stixExport.exportFailed', { error: exportMutation.error.message })}
            </p>
          )}

          {exportMutation.data && (
            <p className="mt-3 text-xs text-success bg-success/10 rounded px-3 py-2">
              {t('stixExport.bundleSaved', { path: exportMutation.data.file_path })}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export default StixExport;
