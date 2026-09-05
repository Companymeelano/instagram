# InstaPilot V13 — AI Attribution Engine

V13 adds a correlation-based, explainable attribution layer on top of verified Meta performance. It does not claim causal certainty. Factor lift is calculated against the user's verified engagement baseline and shrunk toward zero for small samples.

## Install
1. Upload the V12 package files.
2. Run `database/migration_v13.sql` after the existing schema/migrations.
3. Ensure the normal `/ins/api` and cron worker are configured.
4. Rebuild Attribution from Analytics after real Meta media performance exists.

## New endpoints
- GET `/ins/api/attribution/overview`
- POST `/ins/api/attribution/rebuild`

## Factors
Format, caption length, publish window, weekday, CTA presence, AI hook/CTA/brand/score buckets.

## Important
Attribution is evidence-weighted correlation, not causal proof. The UI should describe it as "اثر مشاهده‌شده" rather than guaranteed causation.
