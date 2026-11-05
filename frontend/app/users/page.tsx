"use client";

import { useCallback, useEffect, useState } from "react";
import { api, type AppUser } from "@/lib/api";
import { useMe } from "@/components/AuthGate";
import { DEMO_MODE } from "@/lib/supabase";

/** Readable random password, e.g. "kite-Mango-4821". */
function makePassword(): string {
  const words = ["amber", "river", "cedar", "kite", "mango", "orbit", "pearl", "quartz", "sable", "tiger", "violet", "maple", "lotus", "falcon"];
  const r = (n: number) => crypto.getRandomValues(new Uint32Array(1))[0] % n;
  const w = (s: string) => s[0].toUpperCase() + s.slice(1);
  return `${words[r(words.length)]}-${w(words[r(words.length)])}-${1000 + r(9000)}`;
}

const when = (s: string | null) =>
  s ? new Date(s).toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" }) : "Never";

export default function UsersPage() {
  const me = useMe();
  const [users, setUsers] = useState<AppUser[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const flash = (m: string) => { setToast(m); setTimeout(() => setToast(null), 3500); };
  const refresh = useCallback(() => {
    api.users.list().then(setUsers).catch((e) => setError((e as Error).message));
  }, []);

  useEffect(() => { if (me?.is_admin) refresh(); }, [me?.is_admin, refresh]);

  if (DEMO_MODE) {
    return <main className="page"><div className="callout callout-info">User management needs the backend. Turn off demo mode to use it.</div></main>;
  }
  if (!me) return null;
  if (!me.is_admin) {
    return (
      <main className="page">
        <div className="lib-head"><div><h1>Users</h1></div></div>
        <div className="callout callout-info">Only admins can manage users. Ask an admin to add, reset or remove accounts.</div>
      </main>
    );
  }

  return (
    <main className="page">
      <div className="lib-head">
        <div>
          <h1>Users</h1>
          <p>Add people directly, or approve those who signed up from the login page. Pending accounts can sign in but can't see or upload anything until approved.</p>
        </div>
      </div>

      <AddUser onAdded={(email) => { flash(`${email} can now sign in.`); refresh(); }} />

      {error && <div className="callout" role="alert">{error}</div>}

      {users && users.some((u) => !u.approved) && (
        <section className="panel panel-pending" aria-labelledby="pending">
          <h2 id="pending">Waiting for approval <span className="count">{users.filter((u) => !u.approved).length}</span></h2>
          <ul className="pending-list">
            {users.filter((u) => !u.approved).map((u) => (
              <PendingRow key={u.id} user={u} onDone={(m) => { flash(m); refresh(); }} onError={setError} />
            ))}
          </ul>
        </section>
      )}

      {users === null ? null : (
        <table className="doc-table">
          <thead>
            <tr>
              <th>User</th>
              <th className="hide-sm">Added</th>
              <th className="hide-sm">Last sign-in</th>
              <th aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {users.filter((u) => u.approved).map((u) => (
              <UserRow key={u.id} user={u} isMe={u.id === me.id} onDone={(m) => { flash(m); refresh(); }} onError={setError} />
            ))}
          </tbody>
        </table>
      )}

      {toast && <div className="toast" role="status">{toast}</div>}
    </main>
  );
}

function AddUser({ onAdded }: { onAdded: (email: string) => void }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<{ email: string; password: string } | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.users.create(email.trim(), password);
      setCreated({ email: email.trim(), password });
      onAdded(email.trim());
      setEmail("");
      setPassword("");
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="panel" aria-labelledby="add-user">
      <h2 id="add-user">Add a user</h2>
      <form className="add-user" onSubmit={submit}>
        <div className="add-user-field">
          <label className="input-label" htmlFor="new-email">Email</label>
          <input id="new-email" className="input" type="email" autoComplete="off" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="add-user-field">
          <label className="input-label" htmlFor="new-password">Password <span className="muted">(6+ characters)</span></label>
          <div className="input-wrap">
            <input id="new-password" className="input input-with-btn" type="text" autoComplete="new-password" required minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} />
            <button type="button" className="input-btn" onClick={() => setPassword(makePassword())}>Generate</button>
          </div>
        </div>
        <button className="btn add-user-submit" disabled={busy || !email || password.length < 6}>{busy ? "Adding…" : "Add user"}</button>
      </form>
      {error && <div className="form-error" role="alert">{error}</div>}
      {created && (
        <div className="callout callout-info share">
          <div>
            <strong>Share these with {created.email}:</strong> email <code>{created.email}</code>, password <code>{created.password}</code>. They won&apos;t be shown again.
          </div>
          <button className="btn btn-quiet" onClick={() => {
            navigator.clipboard?.writeText(`Proofsheet login\nEmail: ${created.email}\nPassword: ${created.password}`);
          }}>Copy</button>
          <button className="linklike" onClick={() => setCreated(null)}>Dismiss</button>
        </div>
      )}
    </section>
  );
}

function UserRow({ user, isMe, onDone, onError }: { user: AppUser; isMe: boolean; onDone: (m: string) => void; onError: (m: string) => void }) {
  const [mode, setMode] = useState<"idle" | "reset" | "remove">("idle");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);

  async function run(fn: () => Promise<void>, msg: string) {
    setBusy(true);
    try {
      await fn();
      setMode("idle");
      setPassword("");
      onDone(msg);
    } catch (e) {
      onError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr>
      <td>
        <span className="doc-name">{user.email}</span>
        {isMe && <span className="badge">You</span>}
        {user.is_admin && <span className="badge">Admin</span>}
        {mode === "reset" && (
          <form className="inline-form" onSubmit={(e) => { e.preventDefault(); run(() => api.users.setPassword(user.id, password), `Password changed for ${user.email}. New password: ${password}`); }}>
            <div className="input-wrap">
              <input className="input input-with-btn" aria-label={`New password for ${user.email}`} placeholder="New password" autoFocus minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} />
              <button type="button" className="input-btn" onClick={() => setPassword(makePassword())}>Generate</button>
            </div>
            <button className="btn" disabled={busy || password.length < 6}>{busy ? "Saving…" : "Save"}</button>
            <button type="button" className="btn btn-quiet" onClick={() => setMode("idle")}>Cancel</button>
          </form>
        )}
        {mode === "remove" && (
          <div className="inline-form">
            <span className="danger-text">Remove {user.email}? Their documents and files are deleted too.</span>
            <button className="btn btn-danger-solid" disabled={busy} onClick={() => run(() => api.users.remove(user.id), `${user.email} was removed.`)}>{busy ? "Removing…" : "Remove"}</button>
            <button className="btn btn-quiet" onClick={() => setMode("idle")}>Cancel</button>
          </div>
        )}
      </td>
      <td className="hide-sm doc-sub">{when(user.created_at)}</td>
      <td className="hide-sm doc-sub">{when(user.last_sign_in_at)}</td>
      <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
        {mode === "idle" && (
          <>
            <button className="btn btn-quiet" onClick={() => setMode("reset")}>Reset password</button>
            {!isMe && <button className="btn btn-danger" onClick={() => setMode("remove")}>Remove</button>}
          </>
        )}
      </td>
    </tr>
  );
}

function PendingRow({ user, onDone, onError }: { user: AppUser; onDone: (m: string) => void; onError: (m: string) => void }) {
  const [busy, setBusy] = useState<"approve" | "decline" | null>(null);
  async function run(kind: "approve" | "decline") {
    setBusy(kind);
    try {
      if (kind === "approve") await api.users.approve(user.id);
      else await api.users.remove(user.id);
      onDone(kind === "approve" ? `${user.email} is approved and can now use the app.` : `${user.email} was declined and removed.`);
    } catch (e) {
      onError((e as Error).message);
    } finally {
      setBusy(null);
    }
  }
  return (
    <li>
      <div>
        <span className="doc-name">{user.email}</span>
        <div className="doc-sub">Signed up {when(user.created_at)}</div>
      </div>
      <div className="pending-actions">
        <button className="btn" disabled={busy !== null} onClick={() => run("approve")}>{busy === "approve" ? "Approving…" : "Approve"}</button>
        <button className="btn btn-danger" disabled={busy !== null} onClick={() => run("decline")}>{busy === "decline" ? "Declining…" : "Decline"}</button>
      </div>
    </li>
  );
}
