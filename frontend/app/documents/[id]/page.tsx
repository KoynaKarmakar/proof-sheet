"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { api, humanType, slug } from "@/lib/api";
import type { DocumentRow } from "@/lib/types";
import FieldSheet from "@/components/FieldSheet";
import FlagRail from "@/components/FlagRail";
import BalanceChart from "@/components/BalanceChart";
import TablesView from "@/components/TablesView";
import TextView from "@/components/TextView";
import { ReadProgress } from "@/components/bits";

type Tab = "overview" | "tables" | "text" | "original";

export default function DocumentPage() {
  const { id } = useParams<{ id: string }>();
  const [doc, setDoc] = useState<DocumentRow | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("overview");
  const [focus, setFocus] = useState<string | null>(null);
  const [busy, setBusy] = useState<"export" | "reread" | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  useEffect(() => {
    api.get(id).then(setDoc).catch((e) => setError((e as Error).message));
  }, [id]);

  // While it's being read (here or after a reload), check back every few seconds.
  const reading = doc?.status === "processing" || doc?.status === "uploaded";
  useEffect(() => {
    if (!reading) return;
    const t = setInterval(() => { api.get(id).then(setDoc).catch(() => {}); }, 4000);
    return () => clearInterval(t);
  }, [reading, id]);

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 2600);
    return () => clearTimeout(t);
  }, [toast]);

  const x = doc?.extraction;
  const rowCount = useMemo(() => x?.tables.reduce((n, t) => n + t.rows.length, 0) ?? 0, [x]);

  function jumpTo(label: string) {
    setTab("overview");
    setFocus(null);
    requestAnimationFrame(() => {
      setFocus(label);
      document.getElementById(`field-${slug(label)}`)?.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }

  async function exportExcel() {
    if (!doc) return;
    setBusy("export");
    try {
      await api.downloadExcel(doc);
      setToast("Excel file downloaded");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(null);
    }
  }

  async function reread() {
    if (!doc || !confirm("Read this document again? The current extraction will be replaced.")) return;
    setBusy("reread");
    setError(null);
    try {
      await api.process(doc.id); // returns at once; polling picks up the result
      setDoc(await api.get(doc.id));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(null);
    }
  }

  if (error && !doc) return <main className="page"><Link className="crumb" href="/">Documents</Link><div className="callout">{error}</div></main>;
  if (!doc) return null;

  if (busy === "reread" || doc.status === "processing" || doc.status === "uploaded") {
    return (
      <main className="page">
        <div className="reading">
          <div className="scanline" aria-hidden />
          <strong>Reading {doc.filename}</strong>
          Detecting fields and tables. A page or two takes seconds; long statements can take several minutes on the free tier.
          <ReadProgress progress={doc.usage?.progress} />
          <span className="reading-note">You can leave this page. Reading continues in the background.</span>
        </div>
      </main>
    );
  }

  if (!x) {
    return (
      <main className="page">
        <Link className="crumb" href="/">Documents</Link>
        <div className="callout">This document could not be read{doc.error ? `: ${doc.error}` : "."}</div>
        <button className="btn" onClick={reread}>Try again</button>
      </main>
    );
  }

  const tabs: { id: Tab; label: string; count?: number }[] = [
    { id: "overview", label: "Overview", count: x.fields.length },
    { id: "tables", label: "Tables", count: rowCount },
    { id: "text", label: "Text" },
    { id: "original", label: "Original" },
  ];

  return (
    <main className="page">
      <header className="doc-head">
        <div>
          <Link className="crumb" href="/">Documents</Link>
          <h1>{x.title}</h1>
          <div className="doc-meta">
            <span>{humanType(x.document_type)}</span>
            {x.issuer && <span>Issued by {x.issuer}</span>}
            {x.page_count && <span>{x.page_count} {x.page_count === 1 ? "page" : "pages"}</span>}
            <span>{doc.filename}</span>
          </div>
          {x.summary && <p className="doc-summary">{x.summary}</p>}
        </div>
        <div className="doc-actions">
          <button className="btn btn-quiet" onClick={reread} disabled={!!busy}>Read again</button>
          <button className="btn" onClick={exportExcel} disabled={!!busy}>{busy === "export" ? "Preparing Excel" : "Download Excel"}</button>
        </div>
      </header>

      {error && <div className="callout" role="alert">{error}</div>}

      <nav className="tabs" role="tablist">
        {tabs.map((t) => (
          <button key={t.id} role="tab" className="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}>
            {t.label}{t.count !== undefined && <small>{t.count}</small>}
          </button>
        ))}
      </nav>

      {tab === "overview" && (
        <div className="workspace">
          <div style={{ display: "grid", gridTemplateColumns: "minmax(0, 1fr)", gap: 24, minWidth: 0 }}>
            <BalanceChart tables={x.tables} />
            <FieldSheet fields={x.fields} flags={x.flags} focus={focus} />
          </div>
          <FlagRail flags={x.flags} fields={x.fields} onJump={jumpTo} />
        </div>
      )}
      {tab === "tables" && <TablesView tables={x.tables} />}
      {tab === "text" && <TextView sections={x.sections} />}
      {tab === "original" && (
        <div className="original">
          {!doc.file_url ? (
            <div className="reading">The original file link has expired. Reload the page.</div>
          ) : doc.mime_type === "application/pdf" ? (
            <iframe src={doc.file_url} title={`Original: ${doc.filename}`} />
          ) : (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={doc.file_url} alt={`Original: ${doc.filename}`} />
          )}
        </div>
      )}

      {toast && <div className="toast" role="status">{toast}</div>}
    </main>
  );
}

