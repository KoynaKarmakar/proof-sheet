"use client";

import { useMemo, useState } from "react";
import type { ExtractedTable } from "@/lib/types";

export default function TablesView({ tables }: { tables: ExtractedTable[] }) {
  if (!tables.length) {
    return <div className="sheet empty"><strong>No tables found</strong>This document has no tabular data.</div>;
  }
  return <>{tables.map((t, i) => <TableBlock key={i} table={t} />)}</>;
}

function TableBlock({ table }: { table: ExtractedTable }) {
  const [q, setQ] = useState("");
  const numeric = new Set(table.numeric_columns);
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const indexed = table.rows.map((r, i) => ({ r, n: i + 1 }));
    return needle ? indexed.filter(({ r }) => r.some((c) => c?.toLowerCase().includes(needle))) : indexed;
  }, [q, table.rows]);

  return (
    <section className="table-block">
      <div className="table-head">
        <div>
          <h2>{table.title}</h2>
          <span>
            {table.rows.length} rows, {table.columns.length} columns{table.page ? `, page ${table.page}` : ""}
            {q && `. Showing ${rows.length}.`}
          </span>
        </div>
        <input className="input filter" type="search" placeholder="Filter rows" value={q} onChange={(e) => setQ(e.target.value)} aria-label={`Filter ${table.title}`} />
      </div>
      <div className="scroll">
        <table className="grid">
          <thead>
            <tr>
              <th className="rownum">#</th>
              {table.columns.map((c, i) => <th key={i} className={numeric.has(i) ? "num" : ""}>{c}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map(({ r, n }) => (
              <tr key={n}>
                <td className="rownum">{n}</td>
                {r.map((c, i) => (
                  <td key={i} className={`${numeric.has(i) ? "num" : ""}${c === null ? " null" : ""}`}>{c ?? "-"}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
