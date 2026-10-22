"""
COS20015 Project Phase-05: generate and load the semi-structured and
unstructured data for the Clinical Trial Subject Tracking system.

All data is SYNTHETIC and generated with a fixed seed (20015, the same seed
as Phase-03), so every run produces exactly the same output.

What it does:
  1. Reads the Phase-03 rows it needs from MySQL (visits, rescue medicines,
     adverse events, raised ALT results, consent records).
  2. Builds one eCRF JSON document per COMPLETED visit (102) and saves each
     to data/ecrf/visit_NNN.json, as if received from an external eCRF system.
       - Visits before the protocol amendment (11 May 2026) use form v1.0:
         the Phase-01 shape (subjectId, visitType, scheduledDate, vitals with
         heartRate and bloodPressure only), with no formVersion field.
       - Visits on or after it use form v2.0: formVersion, extra vitals,
         a concomitantMeds array and an optional investigatorComment.
       - Medicines and comments are taken from the Phase-03 tables, so the
         JSON agrees with DrugAdministration, LabResult and AdverseEvent.
  3. Builds one consent PDF per InformedConsent row (34) at the path already
     stored in InformedConsent.document_ref, under storage/.
  4. Re-opens every PDF with pypdf to extract its text and page count, and
     hashes it with SHA-256.
  5. Writes sql/07_load_phase5_data.sql and runs it as ONE transaction, so
     either every row loads or none does.

Requires: reportlab, pypdf  (pip install --user reportlab pypdf)
Usage:    python gen_phase5_data.py
"""
import hashlib
import json
import random
import re
import subprocess
from datetime import date, datetime, timedelta
from pathlib import Path

from pypdf import PdfReader
from reportlab import rl_config
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import PageBreak, Paragraph, SimpleDocTemplate, Spacer, Table

rl_config.invariant = 1          # no timestamps or random IDs inside the PDFs

MYSQL = r"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
LOGIN = "--login-path=claude"    # must be the first mysql option
DB = "clinical_trial_tracking"
SEED = 20015
AMENDMENT = date(2026, 5, 11)    # protocol amendment: consent v2.0 and eCRF form v2.0

ROOT = Path(__file__).resolve().parent.parent          # ...\phase5
ECRF_DIR = ROOT / "data" / "ecrf"
STORAGE = ROOT / "storage"                              # consent/STxx/... sits under here
SQL_OUT = ROOT / "sql" / "07_load_phase5_data.sql"


# ---------------------------------------------------------------- helpers
def query(sql):
    """Run a SELECT through the mysql client and return a list of dicts."""
    res = subprocess.run([MYSQL, LOGIN, "-B", "--raw", DB, "-e", sql],
                         capture_output=True, text=True, encoding="utf-8", check=True)
    lines = res.stdout.splitlines()
    head = lines[0].split("\t")
    return [dict(zip(head, line.split("\t"))) for line in lines[1:]]


def sql_str(s):
    """Quote a Python string as a MySQL string literal."""
    return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"


def d(s):
    return datetime.strptime(s, "%Y-%m-%d").date()


rng = random.Random(SEED)

# ---------------------------------------------------------------- 1. read Phase-03 data
visits = query("""
    SELECT v.visit_id, v.subject_id, v.visit_type, v.scheduled_date, v.scheduled_time, s.sex
    FROM Visit v JOIN Subject s ON s.subject_id = v.subject_id
    WHERE v.status = 'Completed' ORDER BY v.visit_id""")
rescue = query("""
    SELECT da.visit_id, d.drug_name, da.dose_mg
    FROM DrugAdministration da JOIN Drug d ON d.drug_id = da.drug_id
    WHERE d.drug_name <> 'StudyDrugX' ORDER BY da.admin_id""")
aes = query("SELECT ae_id, subject_id, onset_date, severity, narrative FROM AdverseEvent ORDER BY ae_id")
alt_high = {r["visit_id"]: r["value"] for r in query(
    "SELECT visit_id, value FROM LabResult WHERE test_name = 'ALT' AND value > 40")}
consents = query("""
    SELECT ic.consent_id, ic.subject_id, ic.version, ic.signed_date, ic.document_ref,
           s.first_name, s.last_name, st.site_name, st.address,
           CONCAT(i.first_name, ' ', i.last_name) AS investigator, i.qualification
    FROM InformedConsent ic
    JOIN Subject s ON s.subject_id = ic.subject_id
    JOIN Site st   ON st.site_id   = s.site_id
    JOIN Investigator i ON i.site_id = st.site_id
    ORDER BY ic.consent_id""")

# ---------------------------------------------------------------- 2. eCRF JSON documents
# Stable per-subject baselines, so a subject's vitals look consistent across visits.
baseline = {}
for v in visits:
    sid = v["subject_id"]
    if sid not in baseline:
        male = v["sex"] == "M"
        baseline[sid] = {
            "hr": rng.randint(60, 80),
            "sys": rng.randint(108, 128),
            "dia": rng.randint(68, 80),
            "temp": round(rng.uniform(36.4, 36.9), 1),
            "kg": round(rng.uniform(68, 92) if male else rng.uniform(52, 78), 1),
        }
# Subject 25 failed a first screening for high systolic BP (Phase-03 Table 8),
# so keep their readings high-normal on the visits that did go ahead.
baseline["25"].update({"sys": 133, "dia": 85})

# The subject's own medicines, recorded on v2.0 forms (not given at the site,
# so they are NOT in DrugAdministration; the report can show the difference).
own_meds = {
    "7":  {"name": "Cetirizine", "dose": "10 mg", "indication": "Seasonal allergy"},
    "12": {"name": "Vitamin D", "dose": "1000 IU", "indication": "Supplement"},
    "21": {"name": "Cetirizine", "dose": "10 mg", "indication": "Seasonal allergy"},
    "27": {"name": "Vitamin D", "dose": "1000 IU", "indication": "Supplement"},
}

ECRF_DIR.mkdir(parents=True, exist_ok=True)
ecrf_rows = []
for v in visits:
    vid, sid, vdate = v["visit_id"], v["subject_id"], d(v["scheduled_date"])
    b = baseline[sid]
    vitals = {
        "heartRate": b["hr"] + rng.randint(-5, 6),
        "bloodPressure": f"{b['sys'] + rng.randint(-6, 6)}/{b['dia'] + rng.randint(-4, 4)}",
    }
    doc = {"subjectId": int(sid), "visitType": v["visit_type"], "scheduledDate": v["scheduled_date"]}

    if vdate < AMENDMENT:
        doc["vitals"] = vitals                          # form v1.0: Phase-01 shape
    else:
        doc = {"formVersion": "2.0", **doc}
        vitals["temperatureC"] = round(b["temp"] + rng.uniform(-0.2, 0.3), 1)
        vitals["weightKg"] = round(b["kg"] + rng.uniform(-0.6, 0.6), 1)
        doc["vitals"] = vitals

        same_day_aes = [a for a in aes if a["subject_id"] == sid and a["onset_date"] == v["scheduled_date"]]
        meds = []
        for r in rescue:
            if r["visit_id"] != vid:
                continue
            match = [a for a in same_day_aes if r["drug_name"].lower() in a["narrative"].lower()]
            indication = match[0]["narrative"].split(":")[0] if match else "Background medication"
            meds.append({"name": r["drug_name"], "dose": f"{int(float(r['dose_mg']))} mg", "indication": indication})
        if sid in own_meds:
            meds.append(own_meds[sid])
        if meds:
            doc["concomitantMeds"] = meds

        notes = []
        if vid in alt_high:
            notes.append(f"ALT {int(float(alt_high[vid]))} U/L, above ULN 40 U/L; repeat at next visit.")
        for a in same_day_aes:
            notes.append(f"AE reported: {a['narrative'].split(':')[0]} ({a['severity']}).")
        if notes:
            doc["investigatorComment"] = " ".join(notes)

    # Data entry lag: forms reach the system 0 to 4 days after the visit.
    received = datetime.combine(vdate, datetime.min.time()) + timedelta(
        days=rng.randint(0, 4), hours=rng.randint(15, 18), minutes=rng.choice([0, 15, 30, 45]))

    (ECRF_DIR / f"visit_{int(vid):03d}.json").write_text(json.dumps(doc, indent=2), encoding="utf-8")
    ecrf_rows.append((int(vid), json.dumps(doc), received.strftime("%Y-%m-%d %H:%M:%S")))

# ---------------------------------------------------------------- 3. consent PDFs
styles = getSampleStyleSheet()
body = ParagraphStyle("body", parent=styles["BodyText"], fontSize=10, leading=13.5)
h1 = ParagraphStyle("h1", parent=styles["Heading1"], fontSize=15, spaceAfter=4)
h2 = ParagraphStyle("h2", parent=styles["Heading2"], fontSize=11.5, spaceBefore=8, spaceAfter=3)
small = ParagraphStyle("small", parent=body, fontSize=8.5, leading=11)

VERSION_DATE = {"v1.0": "15 January 2026", "v2.0": "11 May 2026"}
STUDY = ("A Phase 1, Randomised, Dose-Escalation Study of the Safety and Tolerability "
         "of StudyDrugX in Healthy Adult Volunteers")
PROTOCOL = "SDX-101 (synthetic)"


def footer(canvas, doc_):
    canvas.saveState()
    canvas.setFont("Helvetica", 7.5)
    canvas.drawString(20 * mm, 12 * mm,
                      "SYNTHETIC DOCUMENT for COS20015 coursework (Swinburne). Not a real study or consent record.")
    canvas.drawRightString(190 * mm, 12 * mm, f"Page {doc_.page}")
    canvas.restoreState()


def build_consent_pdf(c, path):
    code = f"S{int(c['subject_id']):03d}"
    ver = c["version"]
    story = [
        Paragraph("Participant Information Sheet and Consent Form", h1),
        Paragraph(f"<b>Study:</b> {STUDY}", body),
        Paragraph(f"<b>Protocol:</b> {PROTOCOL} &nbsp;&nbsp; <b>Document version:</b> {ver}, "
                  f"dated {VERSION_DATE[ver]}", body),
        Paragraph(f"<b>Site:</b> {c['site_name']}, {c['address']}", body),
        Paragraph(f"<b>Principal Investigator:</b> Dr {c['investigator']} ({c['qualification']})", body),
        Paragraph(f"<b>Participant code:</b> {code}", body),
        Spacer(1, 4),
        Paragraph("1. What is this study about?", h2),
        Paragraph("This study tests how safe StudyDrugX is and how well it is tolerated when healthy "
                  "adults take it at two dose levels, 100 mg and 200 mg. It is the first time the drug is "
                  "being given to people.", body),
        Paragraph("2. What will happen if I take part?", h2),
        Paragraph("You will attend four visits about 14 days apart: a screening visit, two dosing visits "
                  "where you take StudyDrugX at the clinic, and a follow-up visit. At each visit we will "
                  "check your heart rate and blood pressure and take a blood sample for haemoglobin, white "
                  "blood cell count, ALT (a liver enzyme) and creatinine (a kidney marker).", body),
        Paragraph("3. What are the possible risks?", h2),
        Paragraph("Side effects reported with similar medicines include nausea, headache and mild skin "
                  "rash. Medicines such as paracetamol, ondansetron or loratadine may be given at the "
                  "clinic to treat these.", body),
    ]
    if ver == "v2.0":
        story += [
            Paragraph("4. What has changed in this version (v2.0)?", h2),
            Paragraph("Since the study began, some participants had a temporary rise in ALT, a liver "
                      "enzyme, after their second dose. The protocol was amended on 11 May 2026: liver "
                      "blood tests are now reviewed by the investigator before each dose, and an ALT result "
                      "above the upper limit of normal (40 U/L) is repeated at the next visit. Participants "
                      "who were already in the study were asked to sign this version.", body),
        ]
    n = 5 if ver == "v2.0" else 4
    story += [
        Paragraph(f"{n}. How is my information protected?", h2),
        Paragraph(f"Study records identify you by your participant code ({code}). Your name appears only "
                  "on this consent form, which is stored securely at the site. Staff who review study "
                  "reports see the code, not your name.", body),
        Paragraph(f"{n + 1}. Do I have to take part?", h2),
        Paragraph("No. Taking part is voluntary and you may withdraw at any time without giving a reason. "
                  "Withdrawing will not affect your usual medical care.", body),
        PageBreak(),
        Paragraph("Consent Declaration", h1),
        Paragraph(f"Participant code: <b>{code}</b> &nbsp;&nbsp; Document version: <b>{ver}</b>", body),
        Spacer(1, 6),
        Paragraph("I confirm that:", body),
        Paragraph("[x] I have read this information sheet and had the chance to ask questions.", body),
        Paragraph("[x] I understand that taking part is voluntary and that I can withdraw at any time.", body),
        Paragraph("[x] I agree to provide blood samples at each study visit.", body),
        Paragraph("[x] I agree to my coded study data being used to monitor the safety of StudyDrugX.", body),
        Spacer(1, 10),
        Table([
            ["Participant name:", f"{c['first_name']} {c['last_name']}"],
            ["Signature:", "[signed electronically]"],
            ["Date signed:", c["signed_date"]],
            ["", ""],
            ["Investigator:", f"Dr {c['investigator']}"],
            ["Signature:", "[signed electronically]"],
            ["Date signed:", c["signed_date"]],
        ], colWidths=[45 * mm, 110 * mm], hAlign="LEFT"),
        Spacer(1, 14),
        Paragraph("Retain one copy for the participant and file one copy in the site master file.", small),
    ]
    path.parent.mkdir(parents=True, exist_ok=True)
    SimpleDocTemplate(str(path), pagesize=A4, leftMargin=20 * mm, rightMargin=20 * mm,
                      topMargin=18 * mm, bottomMargin=20 * mm,
                      title=f"Consent {code} {ver}", author="COS20015 synthetic data").build(
        story, onFirstPage=footer, onLaterPages=footer)


consent_rows = []
for c in consents:
    pdf_path = STORAGE / Path(c["document_ref"])
    build_consent_pdf(c, pdf_path)
    # Treat the file as unstructured input: read it back like any uploaded PDF.
    data = pdf_path.read_bytes()
    reader = PdfReader(str(pdf_path))
    text = "\n".join(page.extract_text() or "" for page in reader.pages)
    text = re.sub(r"[ \t]+", " ", text).strip()
    consent_rows.append((int(c["consent_id"]), c["document_ref"], len(data),
                         hashlib.sha256(data).hexdigest(), len(reader.pages), text))

# ---------------------------------------------------------------- 4. load SQL (one transaction)
lines = [
    "-- =========================================================================",
    "-- COS20015 Project Phase-05 -- Load generated eCRF JSON and consent PDF metadata",
    "-- GENERATED by scripts/gen_phase5_data.py (seed 20015). Do not edit by hand.",
    "-- Runs as one transaction: every row loads or none does (atomicity).",
    "-- =========================================================================",
    "USE clinical_trial_tracking;",
    "DELETE FROM ConsentDocument;",
    "DELETE FROM VisitECRF;",
    "ALTER TABLE VisitECRF AUTO_INCREMENT = 1;",
    "START TRANSACTION;",
    "",
    f"-- VisitECRF ({len(ecrf_rows)} rows)",
    "INSERT INTO VisitECRF (visit_id, ecrf_doc, received_at) VALUES",
    ",\n".join(f"  ({vid}, {sql_str(doc)}, '{rec}')" for vid, doc, rec in ecrf_rows) + ";",
    "",
    f"-- ConsentDocument ({len(consent_rows)} rows)",
    "INSERT INTO ConsentDocument (consent_id, file_path, file_size_bytes, sha256, page_count, extracted_text) VALUES",
    ",\n".join(f"  ({cid}, {sql_str(fp)}, {size}, '{sha}', {pages}, {sql_str(txt)})"
               for cid, fp, size, sha, pages, txt in consent_rows) + ";",
    "",
    "COMMIT;",
]
SQL_OUT.write_text("\n".join(lines) + "\n", encoding="utf-8")
subprocess.run([MYSQL, LOGIN, DB], input=SQL_OUT.read_text(encoding="utf-8"),
               text=True, encoding="utf-8", check=True)

v1 = sum(1 for _, doc, _ in ecrf_rows if '"formVersion"' not in doc)
print(f"eCRF JSON files : {len(ecrf_rows)} ({v1} form v1.0, {len(ecrf_rows) - v1} form v2.0) in {ECRF_DIR}")
print(f"Consent PDFs    : {len(consent_rows)} in {STORAGE / 'consent'}")
print(f"Load script     : {SQL_OUT}")
print("Loaded into MySQL in one transaction.")
