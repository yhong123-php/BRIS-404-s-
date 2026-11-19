import { useEffect, useState } from 'react';
import { BarChart, PageSkeleton } from '../components/ui';
import { useParams } from 'react-router-dom';
import client from '../api/client';
import type { Purok, PurokStatistics } from '../api/types';

const AGE_BAND_ORDER = ['0-6', '7-12', '13-17', '18-59', '60+'];

export default function PurokStatisticsPage() {
  const { id } = useParams();
  const [purok, setPurok] = useState<Purok | null>(null);
  const [stats, setStats] = useState<PurokStatistics | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Reset before fetching: this route doesn't remount when navigating
    // straight from one purok's stats to another's (same path pattern), so
    // without this the old purok's stats would stay on screen — under the
    // new purok's name once that fetch resolves first — until the new
    // stats request finishes.
    setPurok(null);
    setStats(null);
    setError(null);

    client
      .get('/admin/puroks')
      .then(({ data }) => setPurok(data.data.find((p: Purok) => p.id === Number(id)) ?? null));

    client
      .get(`/admin/puroks/${id}/statistics`)
      .then(({ data }) => setStats(data.data))
      .catch((err) => setError(err?.response?.data?.message ?? 'Could not load statistics.'));
  }, [id]);

  if (error) return <div className="form-error">{error}</div>;
  if (!stats) return <PageSkeleton cards={4} />;

  return (
    <div>
      <div className="page-header">
        <h2>{purok?.name ?? `Purok #${id}`} — Statistics</h2>
      </div>

      <div className="stat-grid">
        <div className="stat-tile">
          <div className="stat-tile-value">{stats.total_residents}</div>
          <div className="stat-tile-label">Total residents</div>
          <div className="stat-tile-breakdown">
            {stats.male_count} male · {stats.female_count} female
          </div>
        </div>
        <div className="stat-tile">
          <div className="stat-tile-value">{stats.total_households}</div>
          <div className="stat-tile-label">Households</div>
        </div>
        <div className="stat-tile">
          <div className="stat-tile-value">{stats.senior_count}</div>
          <div className="stat-tile-label">Senior citizens</div>
        </div>
        <div className="stat-tile">
          <div className="stat-tile-value">{stats.pwd_count}</div>
          <div className="stat-tile-label">PWD</div>
        </div>
        <div className="stat-tile">
          <div className="stat-tile-value">{stats.solo_parent_count}</div>
          <div className="stat-tile-label">Solo parents</div>
        </div>
      </div>

      <div className="detail-section">
        <h3>Sex</h3>
        <BarChart data={[{ label: 'Male', value: stats.male_count }, { label: 'Female', value: stats.female_count }]} />
      </div>

      <div className="detail-section">
        <h3>Age distribution</h3>
        <BarChart data={AGE_BAND_ORDER.map((band) => ({ label: `${band} yrs`, value: stats.age_band_counts[band] ?? 0 }))} />
      </div>
    </div>
  );
}
