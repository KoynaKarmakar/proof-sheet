"use client";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

/** The document rewritten into clean, readable sections. */
export default function TextView({ sections }: { sections: { heading: string; body_markdown: string }[] }) {
  if (!sections.length) {
    return <div className="sheet empty"><strong>No text extracted</strong>The reader did not return any text for this document.</div>;
  }
  return (
    <article className="prose">
      {sections.map((s, i) => (
        <section key={i}>
          {s.heading && <h2>{s.heading}</h2>}
          <ReactMarkdown remarkPlugins={[remarkGfm]}>{s.body_markdown}</ReactMarkdown>
        </section>
      ))}
    </article>
  );
}
