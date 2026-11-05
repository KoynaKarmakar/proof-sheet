"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { api, humanType } from "@/lib/api";
import type { DocumentRow } from "@/lib/types";
import { StatusLabel, Tally } from "@/components/bits";

const ACCEPT = "application/pdf,image/png,image/jpeg,image/webp";

export default function Library() {
  const [docs, setDocs] = useState<DocumentRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [over, setOver] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  const refresh = useCallback(async () => {
    try {
      setDocs(await api.list());
    } catch (e) {
      setError((e as Error).message);
    }
  }, []);

  useEffect(() => { refresh(); }, [refresh]);

  // Poll while something is being read (covers reloads mid-extraction).
  useEffect(() => {
    if (!docs?.some((d) => d.status === "processing")) return;
    const t = setInterval(refresh, 4000);
    return () => clearInterval(t);
  }, [docs, refresh]);

  async function handleFiles(files: FileList | null) {
    if (!files?.length) return;
    setError(null);
    for (const file of Array.from(files)) {
      try {
        const row = await api.upload(file);
        setDocs((d) => [{ ...row, status: "processing" }, ...(d || []).filter((x) => x.id !== row.id)]);
        api.process(row.id)
          .catch((e) => setError(`${file.name}: ${(e as Error).message}`))
          .finally(refresh);
      } catch (e) {
        setError(`${file.name}: ${(e as Error).message}`);
      }
    }
    if (input.current) input.current.value = "";
  }

  async function remove(d: DocumentRow) {
    if (!confirm(`Delete "${d.filename}" and its extracted data?`)) return;
    await api.remove(d.id).catch((e) => setError((e as Error).message));
    refresh();
  }

  return (
    <main className="page">
      <div className="lib-head">
        <div>
          <h1>Documents</h1>
          <p>Upload a bank statement, invoice, GST return or any other document. It is read, the fields are detected, and anything that needs a second look is flagged.</p>
        </div>
      </div>

      <div
        className={`drop${over ? " is-over" : ""}`}
        onDragOver={(e) => { e.preventDefault(); setOver(true); }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); handleFiles(e.dataTransfer.files); }}
      >
        <div>
          <strong>Drop a document here</strong>
          <span>PDF, PNG, JPG or WEBP, up to 20 MB. Scans and phone photos work.</span>
        </div>
        <button className="btn" onClick={() => input.current?.click()}>Choose file</button>
        <input ref={input} type="file" accept={ACCEPT} multiple hidden onChange={(e) => handleFiles(e.target.files)} />
      </div>

      {error && <div className="callout" role="alert">{error}</div>}

      {docs === null ? null : docs.length === 0 ? (
        <div className="doc-table empty">
          <strong>No documents yet</strong>
          Upload one above to see its fields, tables and flags.
        </div>
      ) : (
        <table className="doc-table">
          <thead>
            <tr>
              <th>Document</th>
              <th className="hide-sm">Type</th>
              <th>Status</th>
              <th className="hide-sm">Flags</th>
              <th className="hide-sm">Uploaded</th>
              <th aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {docs.map((d) => (
              <tr key={d.id}>
                <td>
                  {d.status === "completed" ? (
                    <Link className="doc-name" href={`/documents/${d.id}`}>{d.title || d.filename}</Link>
                  ) : (
                    <span className="doc-name">{d.title || d.filename}</span>
                  )}
                  <div className="doc-sub">{d.filename}, {(d.size_bytes / 1024).toFixed(0)} KB</div>
                  {d.status === "failed" && d.error && <div className="doc-sub" style={{ color: "var(--critical)" }}>{d.error}</div>}
                </td>
                <td className="hide-sm">{d.status === "completed" ? humanType(d.document_type) : "-"}</td>
                <td><StatusLabel status={d.status} progress={d.usage?.progress} /></td>
                <td className="hide-sm">{d.flag_counts ? <Tally counts={d.flag_counts} /> : "-"}</td>
                <td className="hide-sm doc-sub">{new Date(d.created_at).toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" })}</td>
                <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                  {d.status === "failed" && (
                    <button className="btn btn-quiet" onClick={() => {
                      setDocs((x) => x!.map((r) => (r.id === d.id ? { ...r, status: "processing" } : r)));
                      api.process(d.id).catch((e) => setError((e as Error).message)).finally(refresh);
                    }}>Try again</button>
                  )}
                  <button className="btn btn-danger" onClick={() => remove(d)} aria-label={`Delete ${d.filename}`}>Delete</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </main>
  );
}
