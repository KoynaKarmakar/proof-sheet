import type { Metadata } from "next";
import "./globals.css";
import AuthGate from "@/components/AuthGate";

export const metadata: Metadata = {
  title: "Proofsheet - document extraction",
  description: "Upload a document, review the extracted fields, tables and flags, export to Excel.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap"
        />
      </head>
      <body>
        <AuthGate>{children}</AuthGate>
      </body>
    </html>
  );
}
