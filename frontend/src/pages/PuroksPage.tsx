import { useEffect, useState } from 'react';
import client from '../api/client';
import type { Purok } from '../api/types';

export default function PuroksPage() {
  const [puroks, setPuroks] = useState<Purok[]>([]);

  useEffect(() => {
    client.get('/admin/puroks').then(({ data }) => setPuroks(data.data));
  }, []);

  return (
    <div>
      <h2>Puroks</h2>
      <table className="data-table">
        <thead>
          <tr>
            <th>Purok</th>
            <th>Number</th>
            <th>Residents</th>
            <th>Households</th>
          </tr>
        </thead>
        <tbody>
          {puroks.map((p) => (
            <tr key={p.id}>
              <td>{p.name}</td>
              <td>{p.purok_number}</td>
              <td>{p.residents_count}</td>
              <td>{p.households_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
