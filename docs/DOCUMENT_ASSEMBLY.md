# Document assembly and e-signature (FUN-14)

## Templates

- **Templates page:** Word (`.docx`) templates live on the *Templates* page. Adding, changing and archiving them needs
  the new permission `manage-document-templates` (built-in `admin`). Anyone who can add documents can use them.
- **Fields:** a field is written in the document between double braces. The Templates page lists the fields
  available: `{{ client.name }}`, `{{ matter.case_number }}`, `{{ matter.court }}`, `{{ lawyer.name }}`,
  `{{ today.long }}`…
  - `{{ ask: Purchase price }}` asks the person creating the document.
  - Unknown fields are flagged when the template is saved (usually a typo) and stay visible in the result as
    `[field]`.
- **Where fields are filled:** body, tables, headers, footers, footnotes and endnotes.
- **Split fields:** Word often splits a field across formatting runs, so a paragraph holding a field is rewritten as
  one run. It keeps the paragraph's settings and the formatting of its first run. Format a whole paragraph, not part
  of a field.
- **Drawings and text boxes:** a paragraph holding a drawing or text box is never rewritten. A field there is filled
  only if Word kept it in one piece.
- **Versions:** replacing a template's file makes a new version. Documents already made are unchanged, and each
  records which template version made it (audit `document_assembled`).

## Creating a document

On a matter's **Documents** tab, *Create from template* asks the template's questions and files the result as a
matter document (`.docx`). It is then searchable (FUN-8) and can be sent for signature.

## E-signature

*Send for signature* sends one of the matter's documents to up to ten people.

### Sending

- Each signer is reached by email and/or SMS with a personal link (48 random characters; only a hash is stored).
- Signers sign in any order, or one after another (the next person is asked when the previous one has signed).

### Signing

- The signer opens the exact file that was sent (PDFs open in the browser; other files download).
- They type their name, draw their signature and confirm their intent to sign.
- Before recording a signature, the system checks that the file is still byte-for-byte the one sent (SHA-256);
  otherwise signing is refused.
- A signer can decline with a reason; that ends the request.

### Following up

- **Remind** sends a fresh link; the old one stops working.
- **Cancel** ends all links. Links also expire (14 days by default).

### Evidence

- For each signer: the time the link was sent, when the document was opened, when it was signed (UTC), the typed name,
  the drawn signature, the IP address and the browser. All of it is audited.
- When everyone has signed, a **signature certificate** (PDF) with this evidence and the document's SHA-256 is filed
  next to the document.

### Legal standing

This is a simple electronic signature with an audit trail. Kenyan law (the Kenya Information and Communications Act
as amended by the Business Laws (Amendment) Act 2020) recognises electronic signatures. It also sets out "advanced"
signatures, and documents that must still be signed by hand or witnessed: for example certain land, succession and
negotiable-instrument documents. Firms must decide which documents may be signed this way.

## Not covered yet

- Placing signatures inside the PDF itself; the certificate is a separate document.
- Advanced electronic signatures through a certification service provider.
- Converting Word to PDF before signing (needs LibreOffice).
- Conditional sections and repeating rows in templates.
