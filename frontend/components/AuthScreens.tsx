"use client";

import { useEffect, useState } from "react";
import { supabase } from "@/lib/supabase";
import { api, type AuthConfig } from "@/lib/api";

type Mode = "signin" | "signup" | "forgot";
export type Notice = { mode: Mode; text: string };

const GOOGLE_ENABLED = process.env.NEXT_PUBLIC_GOOGLE_AUTH === "true";

function GoogleLogo() {
  return (
    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
      <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
      <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
      <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
      <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/>
    </svg>
  );
}

/** "Continue with Google" - Supabase sends the user to Google and back to this page. */
function GoogleButton() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!GOOGLE_ENABLED) return null;
  async function go() {
    setBusy(true);
    setError(null);
    const { error } = await supabase().auth.signInWithOAuth({
      provider: "google",
      options: { redirectTo: `${window.location.origin}/`, queryParams: { prompt: "select_account" } },
    });
    if (error) {
      setError(/not enabled|unsupported provider/i.test(error.message) ? "Google sign-in isn't set up yet. Use email for now." : friendly(error.message));
      setBusy(false);
    }
    // on success the browser leaves for Google, so busy stays on
  }
  return (
    <>
      <button type="button" className="btn-google" onClick={go} disabled={busy}>
        <GoogleLogo />
        <span>{busy ? "Opening Google…" : "Continue with Google"}</span>
      </button>
      {error && <div className="form-error" role="alert">{error}</div>}
      <div className="divider" role="separator"><span>or use email</span></div>
    </>
  );
}

/* ------------------------------------------------------------------ layout */

function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="auth">
      <aside className="auth-side" aria-hidden="true">
        <span className="brand-mark brand-mark-lg"><span>Proof</span>sheet</span>
        <div>
          <p className="auth-pitch">Every figure, read as printed <span>and checked.</span></p>
          <ul className="auth-points">
            <li>Bank statements, GST invoices, ITRs, scans and photos</li>
            <li>Running balances, totals, GSTIN, IFSC and PAN verified</li>
            <li>Review notes for anything that needs a second look</li>
            <li>One-click Excel export</li>
          </ul>
        </div>
        <small>Private workspace. Your documents are visible only to you.</small>
      </aside>
      <main className="auth-main">
        <div className="auth-card">
          <span className="brand-mark auth-brand-sm"><span>Proof</span>sheet</span>
          {children}
        </div>
      </main>
    </div>
  );
}

function PasswordInput({ id, value, onChange, autoComplete, label = "Password", invalid }: {
  id: string; value: string; onChange: (v: string) => void; autoComplete: string; label?: string; invalid?: boolean;
}) {
  const [show, setShow] = useState(false);
  const [caps, setCaps] = useState(false);
  return (
    <>
      <label className="input-label" htmlFor={id}>{label}</label>
      <div className="input-wrap">
        <input
          id={id} className="input input-with-btn" type={show ? "text" : "password"} autoComplete={autoComplete}
          required value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={invalid || undefined}
          onKeyUp={(e) => setCaps(e.getModifierState("CapsLock"))}
        />
        <button type="button" className="input-btn" onClick={() => setShow((v) => !v)} aria-pressed={show} aria-controls={id}>
          {show ? "Hide" : "Show"}
        </button>
      </div>
      {caps && <div className="hint">Caps Lock is on.</div>}
    </>
  );
}

/** 0-4 rough strength: length plus character variety. */
function strength(p: string): { score: number; label: string } {
  if (!p) return { score: 0, label: "" };
  let s = 0;
  if (p.length >= 8) s++;
  if (p.length >= 12) s++;
  if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
  if (/\d/.test(p) && /[^A-Za-z0-9]/.test(p)) s++;
  if (p.length < 8) s = 0;
  return { score: s, label: ["Too short", "Weak", "Fair", "Good", "Strong"][s] };
}

function StrengthMeter({ password }: { password: string }) {
  const { score, label } = strength(password);
  if (!password) return <div className="hint hint-muted">At least 8 characters. Longer is better.</div>;
  return (
    <div className="meter" aria-live="polite">
      <div className="meter-bars">{[1, 2, 3, 4].map((i) => <span key={i} data-on={score >= i ? score : undefined} />)}</div>
      <span className="meter-label">{label}</span>
    </div>
  );
}

const friendly = (m: string) =>
  m === "Invalid login credentials" ? "Email or password is incorrect."
  : /not confirmed/i.test(m) ? "This account isn't confirmed yet. Ask an admin to confirm it."
  : /rate limit|too many/i.test(m) ? "Too many attempts. Wait a few minutes and try again."
  : /fetch|network/i.test(m) ? "Can't reach the sign-in service. Check your connection and try again."
  : m;

/* ------------------------------------------------------------- auth screen */

export function AuthScreen({ notice }: { notice?: Notice | null }) {
  const [mode, setMode] = useState<Mode>(notice?.mode ?? "signin");
  const noticeFor = (m: Mode) => (notice && notice.mode === m ? notice.text : null);
  const [email, setEmail] = useState("");
  const [config, setConfig] = useState<AuthConfig | null>(null);

  useEffect(() => {
    api.authConfig().then(setConfig).catch(() => setConfig({ signup: false, approval: true }));
  }, []);

  return (
    <AuthLayout>
      {mode === "signin" && <SignIn email={email} setEmail={setEmail} config={config} go={setMode} notice={noticeFor("signin")} />}
      {mode === "signup" && <SignUp email={email} setEmail={setEmail} config={config} go={setMode} />}
      {mode === "forgot" && <Forgot email={email} setEmail={setEmail} go={setMode} notice={noticeFor("forgot")} />}
    </AuthLayout>
  );
}

type Step = { email: string; setEmail: (v: string) => void; go: (m: Mode) => void };

function SignIn({ email, setEmail, config, go, notice }: Step & { config: AuthConfig | null; notice?: string | null }) {
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const { error } = await supabase().auth.signInWithPassword({ email: email.trim(), password });
    if (error) setError(friendly(error.message));
    setBusy(false);
  }

  return (
    <form onSubmit={submit} noValidate>
      <h1 className="auth-title">Sign in</h1>
      <p className="auth-sub">Welcome back. Sign in to review your documents.</p>
      {notice && <div className="callout" role="alert">{notice}</div>}
      <GoogleButton />

      <label className="input-label" htmlFor="email">Email</label>
      <input id="email" className="input" type="email" autoComplete="username" inputMode="email" autoFocus={!GOOGLE_ENABLED} required
        value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={!!error || undefined} />

      <PasswordInput id="password" value={password} onChange={setPassword} autoComplete="current-password" invalid={!!error} />
      <div className="row-end">
        <button type="button" className="text-link" onClick={() => go("forgot")}>Forgot password?</button>
      </div>

      {error && <div className="form-error" role="alert">{error}</div>}
      <button className="btn btn-block" disabled={busy || !email || !password}>{busy ? "Signing in…" : "Sign in"}</button>

      <p className="auth-switch">
        {config?.signup === false
          ? <>No account? Ask an admin to add you.</>
          : <>New to Proofsheet? <button type="button" className="text-link" onClick={() => go("signup")}>Create an account</button></>}
      </p>
    </form>
  );
}

function SignUp({ email, setEmail, config, go }: Step & { config: AuthConfig | null }) {
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const mismatch = confirm.length > 0 && confirm !== password;
  const ok = /\S+@\S+\.\S+/.test(email) && password.length >= 8 && confirm === password;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!ok) return;
    setBusy(true);
    setError(null);
    try {
      const res = await api.signup(email.trim(), password);
      if (res.status === "active") {
        const { error } = await supabase().auth.signInWithPassword({ email: email.trim(), password });
        if (error) throw new Error(friendly(error.message));
      } else {
        setPending(true);
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (pending) {
    return (
      <div>
        <div className="auth-icon" aria-hidden="true">✓</div>
        <h1 className="auth-title">Request sent</h1>
        <p className="auth-sub">
          Your account <strong>{email.trim()}</strong> is created and waiting for an admin to approve it.
          Once it&apos;s approved, sign in with the password you just chose.
        </p>
        <button className="btn btn-block" onClick={() => go("signin")}>Back to sign in</button>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate>
      <h1 className="auth-title">Create an account</h1>
      <p className="auth-sub">
        {config?.approval === false ? "Set up your login. You can start right away." : "Request access. An admin approves new accounts before they can be used."}
      </p>
      <GoogleButton />

      <label className="input-label" htmlFor="su-email">Work email</label>
      <input id="su-email" className="input" type="email" autoComplete="email" inputMode="email" autoFocus={!GOOGLE_ENABLED} required
        value={email} onChange={(e) => setEmail(e.target.value)} />

      <PasswordInput id="su-password" value={password} onChange={setPassword} autoComplete="new-password" />
      <StrengthMeter password={password} />

      <PasswordInput id="su-confirm" label="Confirm password" value={confirm} onChange={setConfirm} autoComplete="new-password" invalid={mismatch} />
      {mismatch && <div className="form-error">Passwords don&apos;t match.</div>}

      {error && <div className="form-error" role="alert">{error}</div>}
      <button className="btn btn-block" disabled={busy || !ok}>{busy ? "Creating account…" : config?.approval === false ? "Create account" : "Request access"}</button>

      <p className="auth-switch">
        Already have an account? <button type="button" className="text-link" onClick={() => go("signin")}>Sign in</button>
      </p>
    </form>
  );
}

function Forgot({ email, setEmail, go, notice }: Step & { notice?: string | null }) {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const { error } = await supabase().auth.resetPasswordForEmail(email.trim(), { redirectTo: `${window.location.origin}/` });
    // Don't reveal whether an account exists; only surface real failures like rate limits.
    if (error && /rate limit|too many|fetch|network/i.test(error.message)) setError(friendly(error.message));
    else setSent(true);
    setBusy(false);
  }

  if (sent) {
    return (
      <div>
        <div className="auth-icon" aria-hidden="true">✉</div>
        <h1 className="auth-title">Check your email</h1>
        <p className="auth-sub">
          If <strong>{email.trim()}</strong> has an account, a link to set a new password is on its way.
          The link works once and expires in an hour. Check your spam folder too.
        </p>
        <button className="btn btn-block" onClick={() => go("signin")}>Back to sign in</button>
        <p className="auth-switch">
          Nothing arrived? <button type="button" className="text-link" onClick={() => setSent(false)}>Send again</button>, or ask an admin to reset it for you.
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate>
      <h1 className="auth-title">Reset your password</h1>
      <p className="auth-sub">Enter your email and we&apos;ll send you a link to set a new password.</p>
      {notice && <div className="callout callout-info" role="status">{notice}</div>}

      <label className="input-label" htmlFor="fp-email">Email</label>
      <input id="fp-email" className="input" type="email" autoComplete="username" inputMode="email" autoFocus required
        value={email} onChange={(e) => setEmail(e.target.value)} />

      {error && <div className="form-error" role="alert">{error}</div>}
      <button className="btn btn-block" disabled={busy || !/\S+@\S+\.\S+/.test(email)}>{busy ? "Sending…" : "Send reset link"}</button>

      <p className="auth-switch">
        Remembered it? <button type="button" className="text-link" onClick={() => go("signin")}>Back to sign in</button>
      </p>
    </form>
  );
}

/* ------------------------------------------- after clicking the email link */

export function SetNewPassword({ email, onDone }: { email: string | null; onDone: () => void }) {
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const mismatch = confirm.length > 0 && confirm !== password;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (password.length < 8 || password !== confirm) return;
    setBusy(true);
    setError(null);
    const { error } = await supabase().auth.updateUser({ password });
    setBusy(false);
    if (error) setError(/different from the old/i.test(error.message) ? "Choose a password you haven't used before." : friendly(error.message));
    else onDone();
  }

  return (
    <AuthLayout>
      <form onSubmit={submit} noValidate>
        <h1 className="auth-title">Set a new password</h1>
        <p className="auth-sub">{email ? <>For <strong>{email}</strong>. </> : null}You&apos;ll stay signed in afterwards.</p>

        <PasswordInput id="np-password" label="New password" value={password} onChange={setPassword} autoComplete="new-password" />
        <StrengthMeter password={password} />
        <PasswordInput id="np-confirm" label="Confirm new password" value={confirm} onChange={setConfirm} autoComplete="new-password" invalid={mismatch} />
        {mismatch && <div className="form-error">Passwords don&apos;t match.</div>}

        {error && <div className="form-error" role="alert">{error}</div>}
        <button className="btn btn-block" disabled={busy || password.length < 8 || password !== confirm}>{busy ? "Saving…" : "Save password"}</button>
      </form>
    </AuthLayout>
  );
}

/* ------------------------------------------------ signed in, not approved */

export function PendingApproval({ email, onCheck }: { email: string | null; onCheck: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  return (
    <AuthLayout>
      <div className="auth-icon" aria-hidden="true">⏳</div>
      <h1 className="auth-title">Waiting for approval</h1>
      <p className="auth-sub">
        Your account{email ? <> <strong>{email}</strong></> : null} is set up, but an admin still needs to approve it.
        You&apos;ll be able to upload and review documents as soon as they do.
      </p>
      <button className="btn btn-block" disabled={busy} onClick={async () => { setBusy(true); await onCheck(); setBusy(false); }}>
        {busy ? "Checking…" : "Check again"}
      </button>
      <p className="auth-switch">
        Wrong account? <button type="button" className="text-link" onClick={() => supabase().auth.signOut()}>Sign out</button>
      </p>
    </AuthLayout>
  );
}
