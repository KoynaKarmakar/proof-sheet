"use client";

import { useState } from "react";
import type { Field, Flag, Severity } from "@/lib/types";

const LABEL: Record<Severity, string> = { critical: "Critical", warning: "Check", info: "Note" };

/** Margin notes: what a reviewer should look at, worst first. */
export default function FlagRail({ flags, fields, onJump }: { flags: Flag[]; fields: Field[]; onJump: (label: string) => void }) {
  const [show, setShow] = useState<"all" | "attention">("attention");
  const known = new Set(fields.map((f) => f.label));
  const attention = flags.filter((f) => f.severity !== "info");
  const list = show === "all" ? flags : attention;

  return (
    <aside className="rail" aria-label="Review notes">
      <h2>Review notes</h2>
      <p className="rail-sub">
        {attention.length === 0
          ? "Nothing needs checking. The notes below record what was verified."
          : `${attention.length} ${attention.length === 1 ? "item needs" : "items need"} a second look.`}
      </p>
      <div className="rail-filter" role="group" aria-label="Filter notes">
        <button className="chip" aria-pressed={show === "attention"} onClick={() => setShow("attention")}>Needs checking ({attention.length})</button>
        <button className="chip" aria-pressed={show === "all"} onClick={() => setShow("all")}>All notes ({flags.length})</button>
      </div>
      {list.length === 0 && <p className="rail-sub">No notes in this view.</p>}
      {list.map((f, i) => {
        const target = f.field && known.has(f.field) ? f.field : null;
        const Tag = target ? "button" : "div";
        return (
          <Tag
            key={i}
            className={`note note-${f.severity}`}
            data-target={target ?? undefined}
            onClick={target ? () => onJump(target) : undefined}
          >
            <div className="note-head">
              <span className="note-field">{LABEL[f.severity]}{f.field ? `: ${f.field}` : ""}</span>
              <span className="note-src">{f.source === "validator" ? "Rule check" : "AI reader"}</span>
            </div>
            <p>{f.message}</p>
          </Tag>
        );
      })}
    </aside>
  );
}
