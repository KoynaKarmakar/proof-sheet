"use client";

import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { parseNumber } from "@/lib/api";
import type { ExtractedTable } from "@/lib/types";

const compact = new Intl.NumberFormat("en-IN", { notation: "compact", maximumFractionDigits: 1 });

/**
 * Plots the printed Balance column when the document has a transaction table.
 * Only printed balances are drawn; nothing is computed or filled in.
 */
export default function BalanceChart({ tables }: { tables: ExtractedTable[] }) {
  const table = tables.find((t) => t.columns.some((c) => /balance/i.test(c)));
  if (!table) return null;
  const bal = table.columns.findIndex((c) => /balance/i.test(c));
  const date = table.columns.findIndex((c) => /date/i.test(c));
  const points = table.rows
    .map((r, i) => ({ i: i + 1, date: date >= 0 ? r[date] ?? "" : String(i + 1), balance: parseNumber(r[bal]), printed: r[bal] }))
    .filter((p) => p.balance !== null);
  if (points.length < 3) return null;

  return (
    <section className="sheet chart-box" aria-label="Balance over the statement period">
      <h2>Balance through the period</h2>
      <p>Printed balance after each of {points.length} rows in “{table.title}”.</p>
      <div style={{ width: "100%", height: 220 }}>
        <ResponsiveContainer>
          <LineChart data={points} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
            <CartesianGrid stroke="#e7ecea" vertical={false} />
            <XAxis dataKey="i" tickFormatter={(i) => points[i - 1]?.date?.slice(0, 5) ?? ""} tick={{ fontSize: 11, fill: "#6b7785" }} axisLine={{ stroke: "#d6dedc" }} tickLine={false} minTickGap={24} />
            <YAxis tickFormatter={(v) => compact.format(v)} tick={{ fontSize: 11, fill: "#6b7785" }} axisLine={false} tickLine={false} width={56} />
            <Tooltip
              formatter={(_v, _n, item) => [item.payload.printed, "Balance (printed)"]}
              labelFormatter={(i) => `Row ${i}, ${points[(i as number) - 1]?.date}`}
              contentStyle={{ border: "1px solid #d6dedc", borderRadius: 4, fontSize: 12 }}
            />
            <Line type="stepAfter" dataKey="balance" stroke="#17233b" strokeWidth={1.75} dot={false} activeDot={{ r: 4, fill: "#f6e55a", stroke: "#17233b" }} isAnimationActive={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </section>
  );
}
