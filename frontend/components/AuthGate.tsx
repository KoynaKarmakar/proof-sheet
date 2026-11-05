"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { createContext, useContext, useEffect, useState } from "react";
import type { Session } from "@supabase/supabase-js";
import { DEMO_MODE, supabase } from "@/lib/supabase";
import { api, type Me } from "@/lib/api";
import { AuthScreen, PendingApproval, SetNewPassword, type Notice } from "@/components/AuthScreens";

/**
 * Reads what Supabase put in the URL after a redirect (password-reset link or Google sign-in),
 * before the client consumes it: #type=recovery, or an error in the hash or query string.
 */
function readAuthRedirect(): { recovery: boolean; notice: Notice | null } {
  if (typeof window === "undefined") return { recovery: false, notice: null };
  const h = new URLSearchParams(window.location.hash.replace(/^#/, ""));
  const q = new URLSearchParams(window.location.search);
  const err = h.get("error_description") || q.get("error_description");
  const code = h.get("error_code") || q.get("error_code") || "";
  let notice: Notice | null = null;
  if (err) {
    const text = err.replace(/\+/g, " ");
    if (/otp|expired/i.test(code + text)) {
      notice = { mode: "forgot", text: "That reset link has expired or was already used. Send a new one below." };
    } else if (/signup|not allowed/i.test(code + text)) {
      notice = { mode: "signin", text: "New Google sign-ins are turned off. Ask an admin to add you, or create an account with email." };
    } else {
      notice = { mode: "signin", text: `Sign-in didn't complete: ${text}` };
    }
  }
  return { recovery: h.get("type") === "recovery", notice };
}

const MeCtx = createContext<Me | null>(null);
export const useMe = () => useContext(MeCtx);
export const useUserEmail = () => useContext(MeCtx)?.email ?? null;

export default function AuthGate({ children }: { children: React.ReactNode }) {
  const [session, setSession] = useState<Session | null | undefined>(DEMO_MODE ? null : undefined);
  const [me, setMe] = useState<Me | null>(DEMO_MODE ? { id: "demo", email: "demo@example.com", is_admin: false, approved: true } : null);
  const [configError, setConfigError] = useState<string | null>(null);
  const [recovery, setRecovery] = useState(false);
  const [linkNotice, setLinkNotice] = useState<Notice | null>(null);
  const pathname = usePathname();

  useEffect(() => {
    if (DEMO_MODE) return;
    const redirect = readAuthRedirect();
    if (redirect.recovery) setRecovery(true);
    if (redirect.notice) {
      setLinkNotice(redirect.notice);
      history.replaceState(null, "", window.location.pathname);
    }
    try {
      const sb = supabase();
      sb.auth.getSession().then(({ data }) => setSession(data.session));
      const { data } = sb.auth.onAuthStateChange((event, s) => {
        if (event === "PASSWORD_RECOVERY") setRecovery(true);
        if (event === "SIGNED_OUT") { setRecovery(false); setMe(null); }
        setSession(s);
      });
      return () => data.subscription.unsubscribe();
    } catch (e) {
      setConfigError((e as Error).message);
    }
  }, []);

  // Ask the API who we are (admin? approved?) once signed in.
  const userId = session?.user.id;
  const loadMe = async () => {
    try { setMe(await api.me()); } catch { /* keep the optimistic value */ }
  };
  useEffect(() => {
    if (DEMO_MODE || !userId || !session) return;
    setMe({ id: userId, email: session.user.email ?? null, is_admin: false, approved: true });
    loadMe();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  if (configError) {
    return (
      <div className="auth">
        <div className="auth-card">
          <h1 className="auth-title">Setup needed</h1>
          <p className="auth-sub">{configError} in the frontend environment.</p>
        </div>
      </div>
    );
  }
  if (!DEMO_MODE && session === undefined) return null;
  if (!DEMO_MODE && !session) return <AuthScreen notice={linkNotice} />;
  if (!DEMO_MODE && recovery) {
    return (
      <SetNewPassword
        email={session?.user.email ?? null}
        onDone={() => { setRecovery(false); history.replaceState(null, "", "/"); }}
      />
    );
  }
  if (!DEMO_MODE && me && !me.approved) return <PendingApproval email={me.email} onCheck={loadMe} />;

  const nav = [{ href: "/", label: "Documents" }, ...(me?.is_admin ? [{ href: "/users", label: "Users" }] : [])];
  const active = (href: string) => (href === "/" ? pathname === "/" || pathname.startsWith("/documents") : pathname.startsWith(href));

  return (
    <MeCtx.Provider value={me}>
      {DEMO_MODE && <div className="demo-banner">Demo mode: sample data only, nothing is uploaded or saved.</div>}
      <header className="topbar">
        <div className="topbar-left">
          <Link href="/" className="brand"><span className="brand-mark"><span>Proof</span>sheet</span></Link>
          {nav.length > 1 && (
            <nav className="topnav" aria-label="Main">
              {nav.map((n) => (
                <Link key={n.href} href={n.href} className="topnav-link" aria-current={active(n.href) ? "page" : undefined}>{n.label}</Link>
              ))}
            </nav>
          )}
        </div>
        <div className="topbar-right">
          <span className="hide-sm">{me?.email}</span>
          {me?.is_admin && <span className="badge hide-sm">Admin</span>}
          {!DEMO_MODE && <button className="btn btn-quiet" onClick={() => supabase().auth.signOut()}>Sign out</button>}
        </div>
      </header>
      {children}
    </MeCtx.Provider>
  );
}
