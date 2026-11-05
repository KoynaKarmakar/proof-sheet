import { DEMO_MODE, supabase } from "./supabase";
import type { DocumentRow } from "./types";
import demoDoc from "./demo-document.json";

const API = (process.env.NEXT_PUBLIC_API_URL || "http://localhost:8080").replace(/\/$/, "");

async function token(): Promise<string> {
  const { data } = await supabase().auth.getSession();
  const t = data.session?.access_token;
  if (!t) throw new Error("Your session has ended. Sign in again.");
  return t;
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API}${path}`, {
    ...init,
    headers: { ...(init.headers || {}), Authorization: `Bearer ${await token()}` },
  });
  if (res.status === 204) return undefined as T;
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.error || `Request failed (${res.status})`);
  return body as T;
}

// ------------------------------------------------------------------ demo store
const demo = demoDoc as unknown as DocumentRow;
let demoRows: DocumentRow[] = [demo];
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

export type Me = { id: string; email: string | null; is_admin: boolean; approved: boolean };
export type AppUser = { id: string; email: string | null; created_at: string | null; last_sign_in_at: string | null; is_admin: boolean; approved: boolean };
export type AuthConfig = { signup: boolean; approval: boolean };

/** Calls that work before signing in (sign-up, auth settings). */
async function publicCall<T>(path: string, init: RequestInit = {}): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`${API}${path}`, init);
  } catch {
    throw new Error("Can't reach the server. Check your connection and try again.");
  }
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.error || `Request failed (${res.status})`);
  return body as T;
}

const jsonInit = (method: string, body: unknown): RequestInit => ({
  method,
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body),
});

export const api = {
  async me(): Promise<Me> {
    if (DEMO_MODE) return { id: "demo", email: "demo@example.com", is_admin: false, approved: true };
    return call<Me>("/api/me");
  },

  async authConfig(): Promise<AuthConfig> {
    return publicCall<AuthConfig>("/api/auth-config");
  },

  async signup(email: string, password: string): Promise<{ status: "active" | "pending" }> {
    return publicCall("/api/signup", jsonInit("POST", { email, password }));
  },

  users: {
    async list(): Promise<AppUser[]> {
      return (await call<{ users: AppUser[] }>("/api/users")).users;
    },
    async create(email: string, password: string): Promise<void> {
      await call("/api/users", jsonInit("POST", { email, password }));
    },
    async setPassword(id: string, password: string): Promise<void> {
      await call(`/api/users/${id}`, jsonInit("PATCH", { password }));
    },
    async approve(id: string): Promise<void> {
      await call(`/api/users/${id}`, jsonInit("PATCH", { approved: true }));
    },
    async remove(id: string): Promise<void> {
      await call<void>(`/api/users/${id}`, { method: "DELETE" });
    },
  },

  async list(): Promise<DocumentRow[]> {
    if (DEMO_MODE) return demoRows.map(({ extraction, ...r }) => r);
    return (await call<{ documents: DocumentRow[] }>("/api/documents")).documents;
  },

  async get(id: string): Promise<DocumentRow> {
    if (DEMO_MODE) {
      const d = demoRows.find((r) => r.id === id);
      if (!d) throw new Error("Document not found");
      return d;
    }
    return (await call<{ document: DocumentRow }>(`/api/documents/${id}`)).document;
  },

  async upload(file: File): Promise<DocumentRow> {
    if (DEMO_MODE) {
      const row: DocumentRow = {
        ...demo,
        id: crypto.randomUUID(),
        filename: file.name,
        mime_type: file.type,
        size_bytes: file.size,
        status: "uploaded",
        created_at: new Date().toISOString(),
      };
      demoRows = [row, ...demoRows];
      return row;
    }
    const fd = new FormData();
    fd.append("file", file);
    return (await call<{ document: DocumentRow }>("/api/documents", { method: "POST", body: fd })).document;
  },

  async process(id: string): Promise<DocumentRow> {
    if (DEMO_MODE) {
      await wait(1800);
      demoRows = demoRows.map((r) => (r.id === id ? { ...r, status: "completed", processed_at: new Date().toISOString() } : r));
      return demoRows.find((r) => r.id === id)!;
    }
    return (await call<{ document: DocumentRow }>(`/api/documents/${id}/process`, { method: "POST" })).document;
  },

  async remove(id: string): Promise<void> {
    if (DEMO_MODE) {
      demoRows = demoRows.filter((r) => r.id !== id);
      return;
    }
    await call<void>(`/api/documents/${id}`, { method: "DELETE" });
  },

  async downloadExcel(doc: DocumentRow): Promise<void> {
    if (DEMO_MODE) {
      alert("Excel export needs the backend. Turn off demo mode to download.");
      return;
    }
    const res = await fetch(`${API}/api/documents/${doc.id}/excel`, {
      headers: { Authorization: `Bearer ${await token()}` },
    });
    if (!res.ok) {
      const b = await res.json().catch(() => ({}));
      throw new Error(b.error || "Export failed");
    }
    const blob = await res.blob();
    const name =
      /filename="([^"]+)"/.exec(res.headers.get("Content-Disposition") || "")?.[1] ||
      doc.filename.replace(/\.[^.]+$/, "") + "_extracted.xlsx";
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    URL.revokeObjectURL(a.href);
  },
};

/** Same parsing rules as the backend Validator::parseNumber. */
export function parseNumber(s: string | null | undefined): number | null {
  if (s == null) return null;
  let t = s.trim();
  if (!t || t === "-" || t === "--") return null;
  let neg = false;
  const paren = /^\((.*)\)$/.exec(t);
  if (paren) {
    neg = true;
    t = paren[1];
  }
  t = t.replace(/(₹|rs\.?|inr|usd|\$|%|\b(dr|cr)\b\.?)/gi, "").replace(/[,\s ]/g, "");
  if (t.endsWith("-")) {
    neg = true;
    t = t.replace(/-+$/, "");
  }
  if (!/^-?\d+(\.\d+)?$/.test(t)) return null;
  const v = parseFloat(t);
  return neg ? -Math.abs(v) : v;
}

export const humanType = (t: string | null) =>
  t ? t.replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase()) : "Unclassified";

export const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
