# Super Admin design pack (SA.0)

Status: written by the planner from the Figma Make / Gemini React reference on 28 Nov 2026. Not yet checked in a browser.

## What this is

The approved look and the per-screen specifications for the GFH Super Admin (`/superadmin`). The Super Admin is built in PHP with the Admin templates, tokens and components (see `docs/design-tokens.md`); this pack says what each screen contains and which data it uses. It contains no code that runs.

## Files

- `design-brief.md`: the approved design brief (layout, palette, type, density, charts, states, phone width, limits).
- `screens/`: one specification per screen (`sign-in`, `overview`, `organizations`, `audit-log`, `users-access`, `consolidated-reports`, `system-settings`). Each says which batch builds it.
- `sample-data/`: fictional sample records taken from the React reference (organizations, audit events, users, alerts, order stages, extension measures, feature catalog, operational KPIs). Use them to see realistic shapes and wording. They are not seeds, not real data, and never loaded into a database.

## The React reference

A React, TypeScript and Vite project (`GFH-SuperAdmin-Final.zip`) was built only as a visual reference. It is not part of this repository, must not be copied into it, and never ships: GFH has no JS framework and no build step. If a builder is given it, it is read-only reference for layout and wording. Where this pack and the React reference differ, this pack wins.

## Not carried over from the reference

- The Role Preview switch (the real sign-in page replaces it).
- The Create Organization wizard (organization registration is a separate module; see `screens/organizations.md`).
- Gross Margin (it is not shown anywhere).
- Anything that writes to an organization database or shows a connection string.
