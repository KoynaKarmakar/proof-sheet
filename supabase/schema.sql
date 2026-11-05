-- Run once in Supabase: SQL Editor -> New query -> paste -> Run.

create table if not exists public.documents (
  id              uuid primary key default gen_random_uuid(),
  user_id         uuid not null references auth.users(id) on delete cascade,
  filename        text not null,
  storage_path    text not null,
  mime_type       text not null,
  size_bytes      bigint not null default 0,
  status          text not null default 'uploaded'
                  check (status in ('uploaded', 'processing', 'completed', 'failed')),
  document_type   text,
  title           text,
  extraction      jsonb,          -- cleaned result shown in the UI / exported to Excel
  raw_extraction  jsonb,          -- exactly what the model returned (for debugging)
  flag_counts     jsonb,
  model           text,
  usage           jsonb,
  error           text,
  created_at      timestamptz not null default now(),
  processed_at    timestamptz
);

create index if not exists documents_user_created_idx
  on public.documents (user_id, created_at desc);

-- The PHP API uses the secret key (bypasses RLS) and filters by user_id itself.
-- RLS is still enabled so the publishable key in the browser can never read other rows.
alter table public.documents enable row level security;

drop policy if exists "owner can read" on public.documents;
create policy "owner can read" on public.documents
  for select using (auth.uid() = user_id);

-- Private storage bucket for the uploaded originals.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('documents', 'documents', false, 20971520,
        array['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif'])
on conflict (id) do nothing;
