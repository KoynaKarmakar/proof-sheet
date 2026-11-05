"""
Generates synthetic demo documents for testing the extractor.
All names, account numbers and amounts are fictional.

    pip install reportlab pillow
    python generate_samples.py        # writes PDFs/PNGs next to this script
"""
import os
import random
from datetime import date, timedelta

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import (Paragraph, SimpleDocTemplate, Spacer, Table,
                                TableStyle)

OUT = os.path.dirname(os.path.abspath(__file__))
random.seed(42)
styles = getSampleStyleSheet()
small = ParagraphStyle("small", parent=styles["Normal"], fontSize=8, leading=10)
watermark_text = "SYNTHETIC SAMPLE - NOT A REAL DOCUMENT"


def inr(x: float) -> str:
    """Indian digit grouping: 1,23,456.78"""
    neg = x < 0
    x = abs(x)
    whole, frac = f"{x:.2f}".split(".")
    if len(whole) > 3:
        head, tail = whole[:-3], whole[-3:]
        groups = []
        while len(head) > 2:
            groups.insert(0, head[-2:])
            head = head[:-2]
        if head:
            groups.insert(0, head)
        whole = ",".join(groups) + "," + tail
    return ("-" if neg else "") + whole + "." + frac


def watermark(canvas, doc):
    canvas.saveState()
    canvas.setFont("Helvetica", 7)
    canvas.setFillColor(colors.grey)
    canvas.drawString(15 * mm, 10 * mm, watermark_text)
    canvas.drawRightString(195 * mm, 10 * mm, f"Page {doc.page}")
    canvas.restoreState()


# ---------------------------------------------------------------- bank statement
def bank_statement():
    path = os.path.join(OUT, "sample_bank_statement.pdf")
    doc = SimpleDocTemplate(path, pagesize=A4, leftMargin=15 * mm,
                            rightMargin=15 * mm, topMargin=15 * mm,
                            bottomMargin=18 * mm)
    s = []
    s.append(Paragraph("<b>Demo Co-operative Bank Ltd.</b>", styles["Title"]))
    s.append(Paragraph("Andheri East Branch, Mumbai 400069", styles["Normal"]))
    s.append(Spacer(1, 6))
    s.append(Paragraph("<b>STATEMENT OF ACCOUNT</b>", styles["Heading2"]))

    meta = [
        ["Account Holder", "Shree Ganesh Auto Spares Pvt Ltd", "Account No.", "50200012345678"],
        ["Account Type", "Current Account", "IFSC", "DEMO0001234"],
        ["Customer ID", "CUST88231", "MICR", "400999123"],
        ["Statement Period", "01-Sep-2026 to 30-Sep-2026", "Currency", "INR"],
        ["Address", "Shop 12, Sakinaka Road, Mumbai 400072", "Branch Code", "001234"],
    ]
    t = Table(meta, colWidths=[30 * mm, 62 * mm, 25 * mm, 63 * mm])
    t.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, -1), "Helvetica", 8),
        ("FONT", (0, 0), (0, -1), "Helvetica-Bold", 8),
        ("FONT", (2, 0), (2, -1), "Helvetica-Bold", 8),
        ("GRID", (0, 0), (-1, -1), 0.3, colors.lightgrey),
    ]))
    s.append(t)
    s.append(Spacer(1, 8))

    opening = 412850.75
    bal = opening
    d = date(2026, 9, 1)
    narrs_cr = ["NEFT/CR/TATA MOTORS LTD/INV-{n}", "UPI/CR/{n}/RETAIL SALES",
                "RTGS/CR/MAHINDRA LOGISTICS/{n}", "IMPS/CR/{n}/SHARMA TRADERS",
                "CASH DEPOSIT/BR001234"]
    narrs_dr = ["NEFT/DR/BOSCH LTD/PO-{n}", "UPI/DR/{n}/ELECTRICITY BOARD",
                "CHQ PAID/{n}/SALARY", "RTGS/DR/MINDA INDUSTRIES/{n}",
                "ACH/DR/LOAN EMI/{n}", "GST PAYMENT/CBIC/{n}"]
    rows = [["Date", "Narration", "Chq/Ref No.", "Withdrawal (Dr)", "Deposit (Cr)", "Balance"]]
    rows.append([d.strftime("%d-%m-%Y"), "OPENING BALANCE", "", "", "", inr(opening)])
    tot_dr = tot_cr = 0.0
    n_dr = n_cr = 0
    for _ in range(26):
        d += timedelta(days=random.choice([0, 1, 1, 2]))
        if d > date(2026, 9, 30):
            d = date(2026, 9, 30)
        ref = str(random.randint(100000, 999999))
        if random.random() < 0.45:
            amt = round(random.uniform(8000, 220000), 2)
            bal += amt
            tot_cr += amt
            n_cr += 1
            rows.append([d.strftime("%d-%m-%Y"), random.choice(narrs_cr).format(n=ref),
                         ref, "", inr(amt), inr(bal)])
        else:
            amt = round(random.uniform(3000, 160000), 2)
            bal -= amt
            tot_dr += amt
            n_dr += 1
            rows.append([d.strftime("%d-%m-%Y"), random.choice(narrs_dr).format(n=ref),
                         ref, inr(amt), "", inr(bal)])

    tt = Table(rows, colWidths=[20 * mm, 62 * mm, 20 * mm, 26 * mm, 26 * mm, 26 * mm],
               repeatRows=1)
    tt.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, 0), "Helvetica-Bold", 7.5),
        ("FONT", (0, 1), (-1, -1), "Helvetica", 7.5),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1f3a5f")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("ALIGN", (3, 0), (-1, -1), "RIGHT"),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f2f5f9")]),
        ("GRID", (0, 0), (-1, -1), 0.25, colors.lightgrey),
    ]))
    s.append(tt)
    s.append(Spacer(1, 8))

    summ = [
        ["Opening Balance", "Total Debits", "Total Credits", "Closing Balance"],
        [inr(opening), inr(tot_dr), inr(tot_cr), inr(bal)],
        [f"", f"{n_dr} transactions", f"{n_cr} transactions", ""],
    ]
    st = Table(summ, colWidths=[45 * mm] * 4)
    st.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, 0), "Helvetica-Bold", 8),
        ("FONT", (0, 1), (-1, -1), "Helvetica", 8),
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("BOX", (0, 0), (-1, -1), 0.5, colors.grey),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e8eef6")),
    ]))
    s.append(Paragraph("<b>Account Summary</b>", styles["Heading4"]))
    s.append(st)
    s.append(Spacer(1, 10))
    s.append(Paragraph("This is a computer generated statement and does not require a signature. "
                       "Please report discrepancies within 15 days of receipt.", small))
    doc.build(s, onFirstPage=watermark, onLaterPages=watermark)
    return path


# ---------------------------------------------------------------- GST invoice
def gst_invoice():
    path = os.path.join(OUT, "sample_gst_invoice.pdf")
    doc = SimpleDocTemplate(path, pagesize=A4, leftMargin=15 * mm,
                            rightMargin=15 * mm, topMargin=15 * mm,
                            bottomMargin=18 * mm)
    s = []
    s.append(Paragraph("<b>TAX INVOICE</b>", styles["Title"]))
    hdr = [
        ["Supplier", "Padmini Demo Appliances Pvt Ltd\nPlot 44, MIDC Bhosari, Pune 411026\nGSTIN: 27AABCD1234E1Z5\nState: Maharashtra (27)",
         "Invoice No.", "PDA/26-27/0815"],
        ["", "", "Invoice Date", "14-10-2026"],
        ["Bill To", "Kaveri Electricals\n22 MG Road, Bengaluru 560001\nGSTIN: 29AAAPK5678L1Z2\nState: Karnataka (29)",
         "Place of Supply", "Karnataka (29)"],
        ["", "", "Due Date", "13-11-2026"],
    ]
    h = Table(hdr, colWidths=[22 * mm, 80 * mm, 30 * mm, 48 * mm])
    h.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, -1), "Helvetica", 8),
        ("FONT", (0, 0), (0, -1), "Helvetica-Bold", 8),
        ("FONT", (2, 0), (2, -1), "Helvetica-Bold", 8),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("SPAN", (1, 0), (1, 1)), ("SPAN", (1, 2), (1, 3)),
        ("SPAN", (0, 0), (0, 1)), ("SPAN", (0, 2), (0, 3)),
        ("GRID", (0, 0), (-1, -1), 0.3, colors.lightgrey),
    ]))
    s.append(h)
    s.append(Spacer(1, 8))
    items = [
        ("Mixer Grinder 750W", "85094010", 40, 2150.00),
        ("Ceiling Fan 1200mm", "84145110", 60, 1475.00),
        ("Steam Iron 1250W", "85164000", 35, 980.00),
        ("Air Cooler 40L", "84796000", 12, 6450.00),
    ]
    rows = [["#", "Description", "HSN", "Qty", "Rate", "Taxable Value", "IGST %", "IGST Amt"]]
    taxable_total = igst_total = 0.0
    for i, (desc, hsn, q, rate) in enumerate(items, 1):
        tv = q * rate
        ig = round(tv * 0.18, 2)
        taxable_total += tv
        igst_total += ig
        rows.append([str(i), desc, hsn, str(q), inr(rate), inr(tv), "18%", inr(ig)])
    grand = taxable_total + igst_total
    rows.append(["", "Total", "", "", "", inr(taxable_total), "", inr(igst_total)])
    it = Table(rows, colWidths=[8 * mm, 52 * mm, 20 * mm, 12 * mm, 20 * mm, 26 * mm, 16 * mm, 26 * mm])
    it.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, 0), "Helvetica-Bold", 8),
        ("FONT", (0, 1), (-1, -1), "Helvetica", 8),
        ("FONT", (0, -1), (-1, -1), "Helvetica-Bold", 8),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#3a2f5f")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("ALIGN", (3, 0), (-1, -1), "RIGHT"),
        ("GRID", (0, 0), (-1, -1), 0.25, colors.lightgrey),
    ]))
    s.append(it)
    s.append(Spacer(1, 8))
    tot = Table([
        ["Taxable Value", inr(taxable_total)],
        ["IGST @ 18%", inr(igst_total)],
        ["Grand Total (INR)", inr(grand)],
    ], colWidths=[40 * mm, 35 * mm], hAlign="RIGHT")
    tot.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, -1), "Helvetica", 9),
        ("FONT", (0, -1), (-1, -1), "Helvetica-Bold", 9),
        ("ALIGN", (1, 0), (1, -1), "RIGHT"),
        ("LINEABOVE", (0, -1), (-1, -1), 0.8, colors.black),
    ]))
    s.append(tot)
    s.append(Spacer(1, 10))
    s.append(Paragraph("<b>Bank Details:</b> Demo Co-operative Bank, A/c 50200098765432, IFSC DEMO0001234", small))
    s.append(Paragraph("<b>Terms:</b> Payment within 30 days. Interest @ 18% p.a. on delayed payments. "
                       "Goods once sold will not be taken back. Subject to Pune jurisdiction.", small))
    s.append(Spacer(1, 18))
    s.append(Paragraph("For Padmini Demo Appliances Pvt Ltd<br/><br/>Authorised Signatory", small))
    doc.build(s, onFirstPage=watermark, onLaterPages=watermark)
    return path


def scanned_copy(pdf_path, out_name):
    """Rasterise page 1 and add skew + noise so it looks like a phone scan."""
    from PIL import Image, ImageFilter
    import subprocess
    base = os.path.join(OUT, "_tmp_scan")
    subprocess.run(["pdftoppm", "-r", "130", "-f", "1", "-l", "1", "-png", pdf_path, base], check=True)
    src = next(p for p in os.listdir(OUT) if p.startswith("_tmp_scan"))
    img = Image.open(os.path.join(OUT, src)).convert("L")
    img = img.rotate(1.2, expand=True, fillcolor=235)
    px = img.load()
    for _ in range(img.size[0] * img.size[1] // 60):
        x, y = random.randrange(img.size[0]), random.randrange(img.size[1])
        px[x, y] = max(0, px[x, y] - random.randint(20, 90))
    img = img.filter(ImageFilter.GaussianBlur(0.5))
    out = os.path.join(OUT, out_name)
    img.save(out, quality=80)
    os.remove(os.path.join(OUT, src))
    return out


if __name__ == "__main__":
    b = bank_statement()
    g = gst_invoice()
    sc = scanned_copy(g, "sample_gst_invoice_scanned.jpg")
    print("written:", b, g, sc, sep="\n  ")
