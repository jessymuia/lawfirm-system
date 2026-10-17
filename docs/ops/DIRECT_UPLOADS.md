# Direct uploads to the firm's bucket (ENG-22)

When a firm's files are on an S3-compatible disk, the web app sends a document straight to the bucket. It does not go
through PHP, so large files don't tie up web workers or meet PHP's upload limits. The API still decides what may be
uploaded and checks what arrived before anything becomes a document.

## How it works

1. **Reserve.** `POST /api/v1/documents/uploads` with `{case_id, title, filename, mimetype, size, document_group_id?}`.
   - The route needs `create-documents`, and matter access applies.
   - The type must be an accepted document type, and the size at most `DOCUMENTS_DIRECT_MAX_MB` (default 500).
   - The answer is `{id, url, fields, expires_at}`: a signed S3 POST policy that allows only this object key (under the
     firm's prefix, TEN-12), this `Content-Type` and exactly this many bytes, for 15 minutes. S3 refuses anything else.
2. **Send.** The browser posts `fields` and then the file to `url` as `multipart/form-data`. The request carries no
   cookies or app headers.
3. **Complete.** `POST /api/v1/documents/uploads/{id}/complete`. Only the person who reserved the upload can do this,
   and only once and before it expires. The app checks the object:
   - it exists (else 409 `upload_missing`);
   - its size is what was announced;
   - its content, read from the first bytes, is an accepted type.
   
   If any check fails, the object is deleted (422 `upload_rejected`). Otherwise the app computes the SHA-256 and files
   the document through `StoreDocument` (ENG-9): versioning, text extraction for search and the audit trail work as
   for any upload.

`documents:prune-uploads` runs hourly. It deletes the objects of uploads that were reserved but never completed, and
forgets completed reservations after 30 days.

Without a bucket the API answers 409 `direct_uploads_unavailable`, and the web app uploads through `POST
/api/v1/documents` as before (25 MB limit). It does the same when the browser's type label is not one the API accepts,
because that path reads the content instead. Set `DOCUMENTS_DIRECT_UPLOADS=false` to always upload through the API.

## Bucket CORS

The browser posts from the firm's host to the bucket's origin, so the bucket needs a CORS rule:

```json
[{
  "AllowedOrigins": ["https://*.your-saas-domain.example", "https://firm.example"],
  "AllowedMethods": ["POST"],
  "AllowedHeaders": ["*"],
  "MaxAgeSeconds": 3600
}]
```

Without the rule the browser blocks the upload, and the person sees "The file could not be sent to storage".

## Security notes

- **Keys and access.** The object key is chosen by the server, never by the browser, and always starts with the
  firm's prefix. The storage credentials never leave the server; only a 15-minute policy for one key does.
- **Size.** The bucket enforces it (`content-length-range`), so a reservation cannot be used to store something bigger.
- **Content.** What arrived is not trusted until `complete` has checked it. An unchecked object is never a document
  and is deleted by the prune.
- **Content-Security-Policy.** The staff app sets none. If you add one at the edge, include the bucket's origin in
  `connect-src`.
