import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import client from '../api/client';
import { apiError, formatDate, formatDateTime, PageSkeleton, EmptyRow } from '../components/ui';
import { useToast } from '../context/ToastContext';

/**
 * C3 — Predictive and demographic dashboard (SRS §3.2.1.13, SDD §4.20). Every
 * projection is labelled as a projection, states its data period and method,
 * and is never added to recorded figures.
 */

interface ForecastData {
  generated_at: string | null;
  period: string | null;
  input_from: string | null;
  input_to: string | null;
  method: string | null;
  puroks: Record<string, Record<string, number>>;
  monthly_events: { month: string; event_type: string; c: number }[];
}

export function PredictiveDashboard() {
  const toast = useToast();
  const [data, setData] = useState<ForecastData | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { client.get('/admin/forecasts').then(({ data }) => setData(data.data)); }, []);

  const regenerate = async () => {
    setBusy(true);
    try {
      const { data } = await client.post('/admin/forecasts/generate');
      setData(data.data);
      toast.success('Projections recomputed from the latest records.');
    } catch (err) {
      toast.error(apiError(err));
    } finally {
      setBusy(false);
    }
  };

  if (!data) return <PageSkeleton cards={4} />;
  const names = Object.keys(data.puroks).sort((a, b) => (a === 'Whole barangay' ? -1 : b === 'Whole barangay' ? 1 : a.localeCompare(b, undefined, { numeric: true })));
  const months = Array.from(new Set(data.monthly_events.map((e) => e.month)));
  const count = (month: string, type: string) => data.monthly_events.find((e) => e.month === month && e.event_type === type)?.c ?? 0;
  const maxPop = Math.max(1, ...names.filter((n) => n !== 'Whole barangay').map((n) => data.puroks[n].population ?? 0));

  return (
    <div>
      <div className="page-header">
        <h3 style={{ margin: 0 }}>Projections by purok</h3>
        <button className="button-primary" onClick={regenerate} disabled={busy}>{busy ? 'Computing…' : 'Recompute projections'}</button>
      </div>

      <div className="callout callout-warn">
        <strong>PROJECTIONS — not recorded figures.</strong>{' '}
        {data.generated_at ? (
          <>Period projected: {data.period}. Based on records from {formatDate(data.input_from)} to {formatDate(data.input_to)}. Method: {data.method}. Generated {formatDateTime(data.generated_at)}. Recomputed nightly.</>
        ) : (
          <>No projections yet — click “Recompute projections”.</>
        )}
      </div>

      {names.length > 0 && (
        <div className="table-scroll">
          <table className="data-table projection">
            <thead><tr><th>Purok</th><th>Projected population</th><th>Projected households</th><th>Births (last 12 mo.)</th><th>Deaths (last 12 mo.)</th><th>Projected relief demand (units)</th><th style={{ width: '25%' }}>Projected population</th></tr></thead>
            <tbody>
              {names.map((n) => {
                const p = data.puroks[n];
                return (
                  <tr key={n} style={n === 'Whole barangay' ? { fontWeight: 600 } : undefined}>
                    <td>{n}</td>
                    <td>{p.population}</td>
                    <td>{p.households}</td>
                    <td>{p.births}</td>
                    <td>{p.deaths}</td>
                    <td>{p.relief_demand}</td>
                    <td>
                      {n !== 'Whole barangay' && (
                        <div style={{ background: 'color-mix(in srgb, var(--primary) 20%, transparent)', borderRadius: 4 }}>
                          <div style={{ width: `${((p.population ?? 0) / maxPop) * 100}%`, height: 10, background: 'var(--primary)', borderRadius: 4 }} />
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <h3 style={{ marginTop: '2rem' }}>Recorded population events — last 12 months</h3>
      <table className="data-table">
        <thead><tr><th>Month</th><th>Births</th><th>Deaths</th><th>Transfers in</th><th>Transfers out</th></tr></thead>
        <tbody>
          {months.map((m) => (
            <tr key={m}><td>{m}</td><td>{count(m, 'birth')}</td><td>{count(m, 'death')}</td><td>{count(m, 'transfer_in')}</td><td>{count(m, 'transfer_out')}</td></tr>
          ))}
          {months.length === 0 && <EmptyRow colSpan={5} message="No births, deaths, or transfers recorded in the last 12 months — projections equal current counts until events are recorded." />}
        </tbody>
      </table>
    </div>
  );
}

/** The old /forecasts address now opens the Predictive view of the Dashboard. */
export default function ForecastsRedirect() {
  return <Navigate to="/?view=predictive" replace />;
}
