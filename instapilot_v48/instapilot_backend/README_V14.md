# InstaPilot V14

V14 adds the AI Decision Engine on top of V13 Attribution.

## New endpoints
- `GET /ins/api/decision/next`
- `POST /ins/api/decision/rebuild`

Decision Engine uses verified Meta performance and attribution factors. It does not fabricate performance data. If fewer than 3 verified Meta content samples exist, it returns `insufficient_data`.

## Migration
Run `database/migration_v14.sql` after V13 migration.
