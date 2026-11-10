-- Optional provenance and measurement fields for each safe-zone version.
ALTER TABLE safe_zones
  ADD COLUMN IF NOT EXISTS margin_m DECIMAL(7,2) NULL,
  ADD COLUMN IF NOT EXISTS gpx_file_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS gpx_point_count INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS simplified_point_count INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS simplify_tolerance_m DECIMAL(9,3) NULL,
  ADD COLUMN IF NOT EXISTS simplify_max_deviation_m DECIMAL(9,3) NULL,
  ADD COLUMN IF NOT EXISTS recorded_track_length_m DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS ground_length_m DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS recording_note TEXT NULL;
