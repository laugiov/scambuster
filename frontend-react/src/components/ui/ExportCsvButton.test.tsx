import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ExportCsvButton } from './ExportCsvButton';

vi.mock('@/lib/csv', () => ({
  generateCsv: vi.fn(() => 'csv-content'),
  downloadCsv: vi.fn(),
}));

import { generateCsv, downloadCsv } from '@/lib/csv';

const columns = [
  { key: 'name' as const, header: 'Name' },
  { key: 'value' as const, header: 'Value' },
];

type Row = { name: string; value: string };

describe('ExportCsvButton', () => {
  it('renders without crashing', () => {
    render(<ExportCsvButton<Row> data={[]} columns={columns} filename="test.csv" />);
  });

  it('renders button with export text', () => {
    render(<ExportCsvButton<Row> data={[]} columns={columns} filename="test.csv" />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('disables button when data is empty', () => {
    render(<ExportCsvButton<Row> data={[]} columns={columns} filename="test.csv" />);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('enables button when data has items', () => {
    const data: Row[] = [{ name: 'foo', value: 'bar' }];
    render(<ExportCsvButton<Row> data={data} columns={columns} filename="test.csv" />);
    expect(screen.getByRole('button')).not.toBeDisabled();
  });

  it('calls generateCsv and downloadCsv on click', async () => {
    const data: Row[] = [{ name: 'foo', value: 'bar' }];
    render(<ExportCsvButton<Row> data={data} columns={columns} filename="report.csv" />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button'));
    expect(generateCsv).toHaveBeenCalledWith(data, columns);
    expect(downloadCsv).toHaveBeenCalledWith('csv-content', 'report.csv');
  });
});
