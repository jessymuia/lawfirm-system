# API versioning and deprecation policy (ENG-7)

- The canonical API lives under **`/api/v1`**. All new clients must use it.
- The unversioned **`/api/*`** paths are a **deprecated alias** of v1. Every response carries `Deprecation: true`, `Sunset: <date>` and a `Link: <…>; rel="successor-version"` header. The sunset date is `config/api.php` (`API_LEGACY_SUNSET`), default 31 Mar 2027.
- **Breaking changes** (removing/renaming fields or endpoints, changing types or semantics) ship only in a new major version (`/api/v2`). Additive changes (new optional fields/endpoints) are allowed within v1.
- A superseded version is supported for **at least 6 months** after the successor is released, announced in the changelog and via `Sunset` headers, because self-hosted customers upgrade on their own schedule.
- Self-host releases must keep serving the previous major version for one full release cycle.
