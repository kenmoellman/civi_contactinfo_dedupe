# Contact Info Dedupe

A CiviCRM extension that deduplicates address, email, and phone records within the same contact. It uses a configurable location type priority system to determine which record to keep when duplicates are found.

## Requirements

- CiviCRM 6.9+
- PHP 8.3+
- Smarty 2 or 5

## Installation

Install as a standard CiviCRM extension. The extension creates two database tables on install:

- `civicrm_contactinfo_dedupe_priority` — location type priority rankings
- `civicrm_contactinfo_dedupe_audit_log` — audit trail of all merge operations

## Admin Pages

All pages are under **Administer > System Settings > Contact Info Dedupe**:

- **Priority Configuration** — Drag-and-drop ranking of location types. Lower rank = higher priority. This determines which record is kept when duplicates are found.
- **Dedupe Search** — Find and merge duplicate records by entity type (address, email, phone) with optional contact ID range filtering. Supports individual selection, page selection, and bulk "merge all."
- **Audit Log** — Searchable log of all merge operations, filterable by entity type, contact, and status.

## How It Works

### Global Priority System

A single global ranking of location types (e.g., Invalid=1, Primary=2, Home=3, etc.) is used across all three entity types to determine which record to keep. The record with the higher-priority (lower-numbered) location type is always kept. Blank fields on the kept record are filled from the removed record before it is deleted.

When two records have the same location type priority:
- If there are no field conflicts, the record with the lower ID is kept.
- If there are field conflicts, both records are left in place (merge is skipped).

For all entity types, `is_primary` and `is_billing` flags are transferred from the removed record to the kept record if they were set.

---

### Address Deduplication

**Match criteria:** Same contact + identical `street_address` + `city` + `state_province_id`

**Merge fields:** `postal_code`, `postal_code_suffix`, `supplemental_address_1`, `supplemental_address_2`, `geo_code_1`, `geo_code_2`, `county_id`

**Special handling:**

| Scenario | Behavior |
|---|---|
| `postal_code_suffix` is `0000` | Treated as blank (not a conflict, overwritten by real value) |
| `postal_code` differs only by zero-padding (e.g., `1921` vs `01921`) | Not a conflict; the properly padded version is kept |
| `geo_code_1` or `geo_code_2` differ by ≤ 0.01 degrees (~1.1 km) | Not a conflict; treated as equivalent geocoding |

**Optional checkboxes:**

- **Ignore conflicting +4 ZIP suffix** — When checked, `postal_code_suffix` differences never block a merge. The suffix is blanked on the kept record when they conflict.
- **Ignore conflicting geocodes** — When checked, `geo_code_1`/`geo_code_2` differences never block a merge. Geocodes are blanked on the kept record when they conflict beyond the 0.01 tolerance.

**Apply Consensus button** (for groups of 3+ duplicate addresses):

Before merging, this pre-pass groups addresses by contact + street + city + state. For each merge field, it clusters non-blank values (using tolerance for geocodes, normalization for ZIPs). If a strict majority agrees on a value, outlier records are updated to match. This reduces conflicts so more records can merge cleanly.

---

### Email Deduplication

**Match criteria:** Same contact + identical `LOWER(email)`

**No field conflicts block email merges** — every field has a deterministic merge strategy:

| Field | Merge Rule |
|---|---|
| `email` | Normalized to lowercase on the kept record |
| `on_hold` | Most restrictive value kept (0=none, 1=bounce, 2=spam complaint) |
| `hold_date` | Earliest date kept |
| `reset_date` | Latest date kept, but only if it is after the final `hold_date` (so a re-held email stays held) |
| `signature_text` / `signature_html` | Blank → filled from other record; conflict → highest record ID wins |

---

### Phone Deduplication

**Match criteria:** Same contact + identical normalized phone digits + (same `phone_type_id` OR either is NULL)

**Phone normalization:** All non-digit characters are stripped. If the result is 11 digits starting with `1`, the leading `1` is removed (US country code).

**Keep/remove decision:**

1. If one record has a `phone_type_id` and the other is NULL → always keep the typed one, delete the untyped duplicate.
2. Otherwise, use location type priority as normal.

**Merge fields:**

| Field | Merge Rule |
|---|---|
| `phone_ext` | Blank → filled from other record; conflict blocks same-priority merge |
| `phone_numeric` | Recalculated to match normalized digits |

---

### Audit Logging

Every merge operation logs two entries to `civicrm_contactinfo_dedupe_audit_log`:

1. **changed** — Records which fields were updated on the kept record and their old/new values.
2. **removed** — Stores a full snapshot of the deleted record for recovery reference.

Each entry includes a timestamp and the contact ID of the admin who performed the action.

### Bulk Processing

"Merge All" processes records in batches of 100, re-querying after each batch to handle cascading merges (e.g., 3 duplicate records → first merge leaves 2 → second merge finishes). A modal overlay is shown during processing.

## License

AGPL-3.0
