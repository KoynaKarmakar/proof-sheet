"use client";

import { slug } from "@/lib/api";
import type { Field, Flag } from "@/lib/types";

/** Fields grouped as the document groups them; flagged values get a highlighter mark. */
export default function FieldSheet({ fields, flags, focus }: { fields: Field[]; flags: Flag[]; focus: string | null }) {
  const worst = new Map<string, Flag["severity"]>();
  const rank = { info: 0, warning: 1, critical: 2 };
  for (const f of flags) {
    if (!f.field) continue;
    const cur = worst.get(f.field);
    if (!cur || rank[f.severity] > rank[cur]) worst.set(f.field, f.severity);
  }

  const groups = new Map<string, Field[]>();
  for (const f of fields) {
    const g = f.group || "General";
    groups.set(g, [...(groups.get(g) || []), f]);
  }

  if (!fields.length) {
    return <div className="sheet group"><p className="doc-sub">No fields were detected in this document.</p></div>;
  }

  return (
    <div className="sheet">
      {[...groups.entries()].map(([group, items]) => (
        <section className="group" key={group}>
          <h2>{group}</h2>
          <dl className="fields">
            {items.map((f) => {
              const sev = worst.get(f.label);
              const mark = f.value !== null && sev === "critical" ? "mark-critical" : f.value !== null && sev === "warning" ? "mark-warning" : "";
              return (
                <div
                  key={f.key + f.label}
                  id={`field-${slug(f.label)}`}
                  className={`field${focus === f.label ? " is-focused" : ""}`}
                  title={f.source_text ? `Printed as: ${f.source_text}` : undefined}
                >
                  <dt>
                    {f.label}
                    {f.confidence !== "high" && f.value !== null && <span className="conf">{f.confidence} confidence</span>}
                  </dt>
                  <dd>
                    {f.value === null ? (
                      <span className="na">Not available</span>
                    ) : (
                      <span className={mark}>
                        {f.value_type === "currency" && f.currency && <span className="cur">{f.currency}</span>}
                        {f.value}
                      </span>
                    )}
                  </dd>
                </div>
              );
            })}
          </dl>
        </section>
      ))}
    </div>
  );
}
