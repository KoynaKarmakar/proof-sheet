export type Severity = "info" | "warning" | "critical";

export interface Field {
  key: string;
  label: string;
  group: string;
  value: string | null;
  value_type: "text" | "number" | "currency" | "date" | "percentage" | "identifier";
  currency: string | null;
  page: number | null;
  confidence: "high" | "medium" | "low";
  source_text: string | null;
  numeric: number | null;
}

export interface ExtractedTable {
  title: string;
  page: number | null;
  columns: string[];
  rows: (string | null)[][];
  numeric_columns: number[];
}

export interface Flag {
  severity: Severity;
  field: string | null;
  message: string;
  source: "model" | "validator";
}

export interface Extraction {
  document_type: string;
  title: string;
  summary: string;
  issuer: string | null;
  page_count: number | null;
  fields: Field[];
  tables: ExtractedTable[];
  sections: { heading: string; body_markdown: string }[];
  flags: Flag[];
  flag_counts: Record<Severity, number>;
}

export type Status = "uploaded" | "processing" | "completed" | "failed";

export interface DocumentRow {
  id: string;
  filename: string;
  mime_type: string;
  size_bytes: number;
  status: Status;
  document_type: string | null;
  title: string | null;
  flag_counts: Record<Severity, number> | null;
  error: string | null;
  created_at: string;
  processed_at: string | null;
  model?: string | null;
  /** While reading: progress through the batches of pages. After: token usage. */
  usage?: { progress?: { done: number; total: number }; input_tokens?: number; output_tokens?: number } | null;
  extraction?: Extraction | null;
  file_url?: string | null;
}
