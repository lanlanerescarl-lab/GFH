-- GFH organization database, migration 0006: where each branch is, for location-based analysis.
-- All three are optional text; the Admin settings screen (Stage 6) fills them in.
ALTER TABLE branches
  ADD COLUMN city text,
  ADD COLUMN province text,
  ADD COLUMN region text;
