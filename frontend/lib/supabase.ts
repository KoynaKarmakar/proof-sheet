import { createClient, SupabaseClient } from "@supabase/supabase-js";

export const DEMO_MODE = process.env.NEXT_PUBLIC_DEMO_MODE === "true";

let client: SupabaseClient | null = null;

export function supabase(): SupabaseClient {
  if (!client) {
    const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
    const key = process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY;
    if (!url || !key) {
      throw new Error("Set NEXT_PUBLIC_SUPABASE_URL and NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY");
    }
    client = createClient(url, key, { auth: { persistSession: true, autoRefreshToken: true } });
  }
  return client;
}
