import type { Severity, Status } from "@/lib/types";

const STATUS_TEXT: Record<Status, string> = {
  uploaded: "Waiting to read",
  processing: "Reading",
  completed: "Ready",
  failed: "Could not read",
};

export function StatusLabel({ status, progress }: { status: Status; progress?: { done: number; total: number } | null }) {
  const extra = status === "processing" && progress && progress.total > 1 ? ` · ${progress.done} of ${progress.total}` : "";
  return <span className={`status status-${status}`}>{STATUS_TEXT[status]}{extra}</span>;
}

/** Progress through the batches of pages while a document is being read. */
export function ReadProgress({ progress }: { progress?: { done: number; total: number } | null }) {
  if (!progress || progress.total < 2) return null;
  const pct = Math.round((progress.done / progress.total) * 100);
  return (
    <div className="progress" role="progressbar" aria-valuemin={0} aria-valuemax={progress.total} aria-valuenow={progress.done}>
      <div className="progress-bar"><span style={{ width: `${Math.max(pct, 4)}%` }} /></div>
      <span>{progress.done} of {progress.total} page batches read</span>
    </div>
  );
}

export function Tally({ counts }: { counts: Record<Severity, number> }) {
  if (!counts.critical && !counts.warning) {
    return <span className="tally">Nothing to check</span>;
  }
  return (
    <span className="tally">
      {counts.critical > 0 && <span className="c"><b>{counts.critical}</b> critical</span>}
      {counts.warning > 0 && <span className="w"><b>{counts.warning}</b> to check</span>}
    </span>
  );
}
