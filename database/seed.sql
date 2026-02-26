-- ============================================================
-- Intelis Insights — Seed Data for Development
-- ============================================================
-- Realistic sample aggregate data covering:
--   - 12 months: 2025-03 through 2026-02
--   - 3 provinces, 6 districts, 12 facilities, 4 labs
--   - Monthly period_type
--   - Realistic VL numbers (hundreds to thousands per month)
--   - suppression_applied = FALSE (0) for most rows
--   - TAT metrics for 3 common pairs
--
-- Run AFTER 001–004 migration scripts.
-- ============================================================

USE `intelis_insights`;

-- ===========================================================
-- REFERENCE TABLES
-- ===========================================================

-- ----------------------------------------------------------
-- ref_sample_status
-- ----------------------------------------------------------
INSERT INTO `ref_sample_status` (`status_id`, `status_name`, `status`) VALUES
  (4, 'Accepted',                'active'),
  (6, 'Registered at Lab',      'active'),
  (7, 'Result Approved',        'active'),
  (8, 'Testing In Progress',    'active'),
  (9, 'Awaiting Approval',      'active'),
  (10, 'Result Dispatched',     'active'),
  (11, 'Result Printed',        'active'),
  (2, 'Rejected',               'active');

-- ----------------------------------------------------------
-- ref_geographical_divisions  (country > provinces > districts)
-- ----------------------------------------------------------
INSERT INTO `ref_geographical_divisions` (`geo_id`, `geo_name`, `geo_code`, `geo_parent`, `geo_status`) VALUES
  -- Country
  (1,   'Demo Country',    'DC',     '0',  'active'),
  -- Provinces (parent = country 1)
  (100, 'Central Province', 'CP',    '1',  'active'),
  (200, 'Northern Province','NP',    '1',  'active'),
  (300, 'Southern Province','SP',    '1',  'active'),
  -- Districts under Central Province
  (101, 'Kapiri District',  'KAP',  '100', 'active'),
  (102, 'Mkushi District',  'MKU',  '100', 'active'),
  -- Districts under Northern Province
  (201, 'Kasama District',  'KAS',  '200', 'active'),
  (202, 'Mpulungu District','MPU',  '200', 'active'),
  -- Districts under Southern Province
  (301, 'Livingstone District','LIV','300', 'active'),
  (302, 'Choma District',   'CHO',  '300', 'active');

-- ----------------------------------------------------------
-- ref_facilities  (12 facilities + 4 labs)
-- ----------------------------------------------------------
INSERT INTO `ref_facilities`
  (`facility_id`, `facility_name`, `facility_code`, `vlsm_instance_id`,
   `facility_state_id`, `facility_district_id`, `facility_state`, `facility_district`,
   `facility_type`, `status`, `test_type`)
VALUES
  -- Central Province facilities
  (1001, 'Kapiri Urban Clinic',     'KUC-001', 'VLSM-01', 100, 101, 'Central Province', 'Kapiri District',     1, 'active', 'vl'),
  (1002, 'Kapiri Rural Health Post','KRH-002', 'VLSM-01', 100, 101, 'Central Province', 'Kapiri District',     1, 'active', 'vl'),
  (1003, 'Mkushi District Hospital','MDH-003', 'VLSM-01', 100, 102, 'Central Province', 'Mkushi District',     2, 'active', 'vl'),
  (1004, 'Mkushi Health Centre',    'MHC-004', 'VLSM-01', 100, 102, 'Central Province', 'Mkushi District',     1, 'active', 'vl'),
  -- Northern Province facilities
  (2001, 'Kasama General Hospital', 'KGH-001', 'VLSM-02', 200, 201, 'Northern Province','Kasama District',     2, 'active', 'vl'),
  (2002, 'Kasama Urban Clinic',     'KUC-002', 'VLSM-02', 200, 201, 'Northern Province','Kasama District',     1, 'active', 'vl'),
  (2003, 'Mpulungu Health Centre',  'MHC-003', 'VLSM-02', 200, 202, 'Northern Province','Mpulungu District',   1, 'active', 'vl'),
  (2004, 'Mpulungu Rural Clinic',   'MRC-004', 'VLSM-02', 200, 202, 'Northern Province','Mpulungu District',   1, 'active', 'vl'),
  -- Southern Province facilities
  (3001, 'Livingstone Central Hosp','LCH-001', 'VLSM-03', 300, 301, 'Southern Province','Livingstone District', 2, 'active', 'vl'),
  (3002, 'Livingstone Clinic',      'LC-002',  'VLSM-03', 300, 301, 'Southern Province','Livingstone District', 1, 'active', 'vl'),
  (3003, 'Choma District Hospital', 'CDH-003', 'VLSM-03', 300, 302, 'Southern Province','Choma District',      2, 'active', 'vl'),
  (3004, 'Choma Health Post',       'CHP-004', 'VLSM-03', 300, 302, 'Southern Province','Choma District',      1, 'active', 'vl'),
  -- Labs (facility_type = 3 for laboratory)
  (5001, 'Central Reference Lab',   'CRL-001', 'VLSM-01', 100, 101, 'Central Province', 'Kapiri District',     3, 'active', 'vl'),
  (5002, 'Northern Provincial Lab', 'NPL-002', 'VLSM-02', 200, 201, 'Northern Province','Kasama District',     3, 'active', 'vl'),
  (5003, 'Southern Provincial Lab', 'SPL-003', 'VLSM-03', 300, 301, 'Southern Province','Livingstone District', 3, 'active', 'vl'),
  (5004, 'National VL Reference Lab','NRL-004','VLSM-01', 100, 101, 'Central Province', 'Kapiri District',     3, 'active', 'vl');

-- ----------------------------------------------------------
-- ref_vl_rejection_reasons
-- ----------------------------------------------------------
INSERT INTO `ref_vl_rejection_reasons`
  (`rejection_reason_id`, `rejection_reason_name`, `rejection_type`, `rejection_reason_status`, `rejection_reason_code`)
VALUES
  (1, 'Hemolyzed sample',              'general',      'active', 'REJ-001'),
  (2, 'Insufficient volume',           'general',      'active', 'REJ-002'),
  (3, 'Sample leaked in transit',      'general',      'active', 'REJ-003'),
  (4, 'Clotted sample',                'whole blood',  'active', 'REJ-004'),
  (5, 'Missing patient information',   'general',      'active', 'REJ-005'),
  (6, 'Expired collection tube',       'general',      'active', 'REJ-006'),
  (7, 'Improper storage temperature',  'plasma',       'active', 'REJ-007'),
  (8, 'DBS card not fully saturated',  'dbs',          'active', 'REJ-008');

-- ----------------------------------------------------------
-- ref_vl_test_reasons
-- ----------------------------------------------------------
INSERT INTO `ref_vl_test_reasons`
  (`test_reason_id`, `test_reason_name`, `parent_reason`, `test_reason_status`)
VALUES
  (1, 'Routine VL Monitoring',         0, 'active'),
  (2, 'Suspected Treatment Failure',   0, 'active'),
  (3, 'Post-EAC Follow-up',           2, 'active'),
  (4, 'Baseline / Initial VL',         0, 'active'),
  (5, 'Repeat After High VL',          2, 'active');

-- ----------------------------------------------------------
-- ref_vl_sample_types
-- ----------------------------------------------------------
INSERT INTO `ref_vl_sample_types` (`sample_id`, `sample_name`, `status`) VALUES
  (1, 'Whole Blood',    'active'),
  (2, 'Plasma',         'active'),
  (3, 'DBS',            'active');


-- ===========================================================
-- AGGREGATE TABLES — Monthly data, 12 months
-- ===========================================================
-- All seed rows use suppression_applied = 0 (FALSE) and
-- vlsm_country_id = 1. source_max_last_modified set to
-- end-of-month timestamps for realism.

-- ----------------------------------------------------------
-- vl_agg_volume_status
-- ----------------------------------------------------------
-- One row per (month, status) across the whole country.
-- Status 7 (Approved) is the largest bucket; 8,9 are pending.
INSERT INTO `vl_agg_volume_status`
  (`period_type`, `period_start_date`, `result_status_id`, `result_status_name`,
   `vlsm_country_id`, `n_samples`, `source_max_last_modified`, `suppression_applied`)
VALUES
  -- 2025-03
  ('month','2025-03-01', 7,'Result Approved',     1, 3842, '2025-03-31 23:45:00', 0),
  ('month','2025-03-01', 10,'Result Dispatched',   1, 3610, '2025-03-31 23:30:00', 0),
  ('month','2025-03-01', 8,'Testing In Progress',  1,  185, '2025-03-31 22:00:00', 0),
  ('month','2025-03-01', 9,'Awaiting Approval',    1,   47, '2025-03-31 21:00:00', 0),
  ('month','2025-03-01', 2,'Rejected',             1,  156, '2025-03-31 20:00:00', 0),
  -- 2025-04
  ('month','2025-04-01', 7,'Result Approved',      1, 3956, '2025-04-30 23:45:00', 0),
  ('month','2025-04-01', 10,'Result Dispatched',   1, 3720, '2025-04-30 23:30:00', 0),
  ('month','2025-04-01', 8,'Testing In Progress',  1,  192, '2025-04-30 22:00:00', 0),
  ('month','2025-04-01', 9,'Awaiting Approval',    1,   44, '2025-04-30 21:00:00', 0),
  ('month','2025-04-01', 2,'Rejected',             1,  162, '2025-04-30 20:00:00', 0),
  -- 2025-05
  ('month','2025-05-01', 7,'Result Approved',      1, 4105, '2025-05-31 23:45:00', 0),
  ('month','2025-05-01', 10,'Result Dispatched',   1, 3890, '2025-05-31 23:30:00', 0),
  ('month','2025-05-01', 8,'Testing In Progress',  1,  168, '2025-05-31 22:00:00', 0),
  ('month','2025-05-01', 9,'Awaiting Approval',    1,   52, '2025-05-31 21:00:00', 0),
  ('month','2025-05-01', 2,'Rejected',             1,  148, '2025-05-31 20:00:00', 0),
  -- 2025-06
  ('month','2025-06-01', 7,'Result Approved',      1, 4230, '2025-06-30 23:45:00', 0),
  ('month','2025-06-01', 10,'Result Dispatched',   1, 3985, '2025-06-30 23:30:00', 0),
  ('month','2025-06-01', 8,'Testing In Progress',  1,  201, '2025-06-30 22:00:00', 0),
  ('month','2025-06-01', 9,'Awaiting Approval',    1,   38, '2025-06-30 21:00:00', 0),
  ('month','2025-06-01', 2,'Rejected',             1,  171, '2025-06-30 20:00:00', 0),
  -- 2025-07
  ('month','2025-07-01', 7,'Result Approved',      1, 4378, '2025-07-31 23:45:00', 0),
  ('month','2025-07-01', 10,'Result Dispatched',   1, 4150, '2025-07-31 23:30:00', 0),
  ('month','2025-07-01', 8,'Testing In Progress',  1,  175, '2025-07-31 22:00:00', 0),
  ('month','2025-07-01', 9,'Awaiting Approval',    1,   55, '2025-07-31 21:00:00', 0),
  ('month','2025-07-01', 2,'Rejected',             1,  180, '2025-07-31 20:00:00', 0),
  -- 2025-08
  ('month','2025-08-01', 7,'Result Approved',      1, 4512, '2025-08-31 23:45:00', 0),
  ('month','2025-08-01', 10,'Result Dispatched',   1, 4290, '2025-08-31 23:30:00', 0),
  ('month','2025-08-01', 8,'Testing In Progress',  1,  162, '2025-08-31 22:00:00', 0),
  ('month','2025-08-01', 9,'Awaiting Approval',    1,   60, '2025-08-31 21:00:00', 0),
  ('month','2025-08-01', 2,'Rejected',             1,  188, '2025-08-31 20:00:00', 0),
  -- 2025-09
  ('month','2025-09-01', 7,'Result Approved',      1, 4285, '2025-09-30 23:45:00', 0),
  ('month','2025-09-01', 10,'Result Dispatched',   1, 4060, '2025-09-30 23:30:00', 0),
  ('month','2025-09-01', 8,'Testing In Progress',  1,  195, '2025-09-30 22:00:00', 0),
  ('month','2025-09-01', 9,'Awaiting Approval',    1,   42, '2025-09-30 21:00:00', 0),
  ('month','2025-09-01', 2,'Rejected',             1,  165, '2025-09-30 20:00:00', 0),
  -- 2025-10
  ('month','2025-10-01', 7,'Result Approved',      1, 4620, '2025-10-31 23:45:00', 0),
  ('month','2025-10-01', 10,'Result Dispatched',   1, 4380, '2025-10-31 23:30:00', 0),
  ('month','2025-10-01', 8,'Testing In Progress',  1,  183, '2025-10-31 22:00:00', 0),
  ('month','2025-10-01', 9,'Awaiting Approval',    1,   57, '2025-10-31 21:00:00', 0),
  ('month','2025-10-01', 2,'Rejected',             1,  192, '2025-10-31 20:00:00', 0),
  -- 2025-11
  ('month','2025-11-01', 7,'Result Approved',      1, 4410, '2025-11-30 23:45:00', 0),
  ('month','2025-11-01', 10,'Result Dispatched',   1, 4175, '2025-11-30 23:30:00', 0),
  ('month','2025-11-01', 8,'Testing In Progress',  1,  210, '2025-11-30 22:00:00', 0),
  ('month','2025-11-01', 9,'Awaiting Approval',    1,   48, '2025-11-30 21:00:00', 0),
  ('month','2025-11-01', 2,'Rejected',             1,  175, '2025-11-30 20:00:00', 0),
  -- 2025-12
  ('month','2025-12-01', 7,'Result Approved',      1, 3920, '2025-12-31 23:45:00', 0),
  ('month','2025-12-01', 10,'Result Dispatched',   1, 3700, '2025-12-31 23:30:00', 0),
  ('month','2025-12-01', 8,'Testing In Progress',  1,  225, '2025-12-31 22:00:00', 0),
  ('month','2025-12-01', 9,'Awaiting Approval',    1,   65, '2025-12-31 21:00:00', 0),
  ('month','2025-12-01', 2,'Rejected',             1,  158, '2025-12-31 20:00:00', 0),
  -- 2026-01
  ('month','2026-01-01', 7,'Result Approved',      1, 4750, '2026-01-31 23:45:00', 0),
  ('month','2026-01-01', 10,'Result Dispatched',   1, 4510, '2026-01-31 23:30:00', 0),
  ('month','2026-01-01', 8,'Testing In Progress',  1,  178, '2026-01-31 22:00:00', 0),
  ('month','2026-01-01', 9,'Awaiting Approval',    1,   62, '2026-01-31 21:00:00', 0),
  ('month','2026-01-01', 2,'Rejected',             1,  198, '2026-01-31 20:00:00', 0),
  -- 2026-02
  ('month','2026-02-01', 7,'Result Approved',      1, 4580, '2026-02-28 23:45:00', 0),
  ('month','2026-02-01', 10,'Result Dispatched',   1, 4340, '2026-02-28 23:30:00', 0),
  ('month','2026-02-01', 8,'Testing In Progress',  1,  190, '2026-02-28 22:00:00', 0),
  ('month','2026-02-01', 9,'Awaiting Approval',    1,   50, '2026-02-28 21:00:00', 0),
  ('month','2026-02-01', 2,'Rejected',             1,  185, '2026-02-28 20:00:00', 0);


-- ----------------------------------------------------------
-- vl_agg_volume_org
-- ----------------------------------------------------------
-- One row per (month, facility, lab, province).
-- Each facility sends samples to its provincial lab; some also
-- to the National VL Reference Lab (5004).
-- Larger facilities (hospitals) have higher volumes.

INSERT INTO `vl_agg_volume_org`
  (`period_type`, `period_start_date`, `facility_id`, `facility_name`,
   `lab_id`, `lab_name`, `province_id`, `province_name`,
   `vlsm_country_id`, `n_samples`, `source_max_last_modified`, `suppression_applied`)
VALUES
  -- ---- 2025-03 ----
  ('month','2025-03-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 385, '2025-03-31 23:45:00', 0),
  ('month','2025-03-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 142, '2025-03-31 23:40:00', 0),
  ('month','2025-03-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 520, '2025-03-31 23:35:00', 0),
  ('month','2025-03-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 198, '2025-03-31 23:30:00', 0),
  ('month','2025-03-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 610, '2025-03-31 23:25:00', 0),
  ('month','2025-03-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 275, '2025-03-31 23:20:00', 0),
  ('month','2025-03-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 180, '2025-03-31 23:15:00', 0),
  ('month','2025-03-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1,  95, '2025-03-31 23:10:00', 0),
  ('month','2025-03-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 580, '2025-03-31 23:05:00', 0),
  ('month','2025-03-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 245, '2025-03-31 23:00:00', 0),
  ('month','2025-03-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 430, '2025-03-31 22:55:00', 0),
  ('month','2025-03-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 120, '2025-03-31 22:50:00', 0),

  -- ---- 2025-04 ----
  ('month','2025-04-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 398, '2025-04-30 23:45:00', 0),
  ('month','2025-04-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 155, '2025-04-30 23:40:00', 0),
  ('month','2025-04-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 535, '2025-04-30 23:35:00', 0),
  ('month','2025-04-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 210, '2025-04-30 23:30:00', 0),
  ('month','2025-04-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 628, '2025-04-30 23:25:00', 0),
  ('month','2025-04-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 290, '2025-04-30 23:20:00', 0),
  ('month','2025-04-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 188, '2025-04-30 23:15:00', 0),
  ('month','2025-04-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 102, '2025-04-30 23:10:00', 0),
  ('month','2025-04-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 595, '2025-04-30 23:05:00', 0),
  ('month','2025-04-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 255, '2025-04-30 23:00:00', 0),
  ('month','2025-04-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 445, '2025-04-30 22:55:00', 0),
  ('month','2025-04-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 128, '2025-04-30 22:50:00', 0),

  -- ---- 2025-05 ----
  ('month','2025-05-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 410, '2025-05-31 23:45:00', 0),
  ('month','2025-05-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 160, '2025-05-31 23:40:00', 0),
  ('month','2025-05-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 548, '2025-05-31 23:35:00', 0),
  ('month','2025-05-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 215, '2025-05-31 23:30:00', 0),
  ('month','2025-05-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 645, '2025-05-31 23:25:00', 0),
  ('month','2025-05-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 302, '2025-05-31 23:20:00', 0),
  ('month','2025-05-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 195, '2025-05-31 23:15:00', 0),
  ('month','2025-05-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 108, '2025-05-31 23:10:00', 0),
  ('month','2025-05-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 615, '2025-05-31 23:05:00', 0),
  ('month','2025-05-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 262, '2025-05-31 23:00:00', 0),
  ('month','2025-05-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 458, '2025-05-31 22:55:00', 0),
  ('month','2025-05-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 132, '2025-05-31 22:50:00', 0),

  -- ---- 2025-06 ----
  ('month','2025-06-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 425, '2025-06-30 23:45:00', 0),
  ('month','2025-06-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 168, '2025-06-30 23:40:00', 0),
  ('month','2025-06-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 562, '2025-06-30 23:35:00', 0),
  ('month','2025-06-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 222, '2025-06-30 23:30:00', 0),
  ('month','2025-06-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 660, '2025-06-30 23:25:00', 0),
  ('month','2025-06-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 310, '2025-06-30 23:20:00', 0),
  ('month','2025-06-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 205, '2025-06-30 23:15:00', 0),
  ('month','2025-06-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 112, '2025-06-30 23:10:00', 0),
  ('month','2025-06-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 632, '2025-06-30 23:05:00', 0),
  ('month','2025-06-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 270, '2025-06-30 23:00:00', 0),
  ('month','2025-06-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 472, '2025-06-30 22:55:00', 0),
  ('month','2025-06-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 138, '2025-06-30 22:50:00', 0),

  -- ---- 2025-07 ----
  ('month','2025-07-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 440, '2025-07-31 23:45:00', 0),
  ('month','2025-07-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 172, '2025-07-31 23:40:00', 0),
  ('month','2025-07-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 578, '2025-07-31 23:35:00', 0),
  ('month','2025-07-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 230, '2025-07-31 23:30:00', 0),
  ('month','2025-07-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 680, '2025-07-31 23:25:00', 0),
  ('month','2025-07-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 318, '2025-07-31 23:20:00', 0),
  ('month','2025-07-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 212, '2025-07-31 23:15:00', 0),
  ('month','2025-07-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 118, '2025-07-31 23:10:00', 0),
  ('month','2025-07-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 650, '2025-07-31 23:05:00', 0),
  ('month','2025-07-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 278, '2025-07-31 23:00:00', 0),
  ('month','2025-07-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 488, '2025-07-31 22:55:00', 0),
  ('month','2025-07-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 145, '2025-07-31 22:50:00', 0),

  -- ---- 2025-08 ----
  ('month','2025-08-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 455, '2025-08-31 23:45:00', 0),
  ('month','2025-08-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 178, '2025-08-31 23:40:00', 0),
  ('month','2025-08-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 592, '2025-08-31 23:35:00', 0),
  ('month','2025-08-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 238, '2025-08-31 23:30:00', 0),
  ('month','2025-08-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 698, '2025-08-31 23:25:00', 0),
  ('month','2025-08-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 325, '2025-08-31 23:20:00', 0),
  ('month','2025-08-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 220, '2025-08-31 23:15:00', 0),
  ('month','2025-08-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 122, '2025-08-31 23:10:00', 0),
  ('month','2025-08-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 668, '2025-08-31 23:05:00', 0),
  ('month','2025-08-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 285, '2025-08-31 23:00:00', 0),
  ('month','2025-08-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 502, '2025-08-31 22:55:00', 0),
  ('month','2025-08-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 150, '2025-08-31 22:50:00', 0),

  -- ---- 2025-09 ----
  ('month','2025-09-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 432, '2025-09-30 23:45:00', 0),
  ('month','2025-09-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 165, '2025-09-30 23:40:00', 0),
  ('month','2025-09-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 560, '2025-09-30 23:35:00', 0),
  ('month','2025-09-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 225, '2025-09-30 23:30:00', 0),
  ('month','2025-09-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 665, '2025-09-30 23:25:00', 0),
  ('month','2025-09-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 305, '2025-09-30 23:20:00', 0),
  ('month','2025-09-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 208, '2025-09-30 23:15:00', 0),
  ('month','2025-09-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 115, '2025-09-30 23:10:00', 0),
  ('month','2025-09-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 640, '2025-09-30 23:05:00', 0),
  ('month','2025-09-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 268, '2025-09-30 23:00:00', 0),
  ('month','2025-09-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 475, '2025-09-30 22:55:00', 0),
  ('month','2025-09-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 135, '2025-09-30 22:50:00', 0),

  -- ---- 2025-10 ----
  ('month','2025-10-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 465, '2025-10-31 23:45:00', 0),
  ('month','2025-10-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 182, '2025-10-31 23:40:00', 0),
  ('month','2025-10-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 605, '2025-10-31 23:35:00', 0),
  ('month','2025-10-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 245, '2025-10-31 23:30:00', 0),
  ('month','2025-10-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 715, '2025-10-31 23:25:00', 0),
  ('month','2025-10-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 335, '2025-10-31 23:20:00', 0),
  ('month','2025-10-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 228, '2025-10-31 23:15:00', 0),
  ('month','2025-10-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 128, '2025-10-31 23:10:00', 0),
  ('month','2025-10-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 685, '2025-10-31 23:05:00', 0),
  ('month','2025-10-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 292, '2025-10-31 23:00:00', 0),
  ('month','2025-10-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 515, '2025-10-31 22:55:00', 0),
  ('month','2025-10-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 155, '2025-10-31 22:50:00', 0),

  -- ---- 2025-11 ----
  ('month','2025-11-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 445, '2025-11-30 23:45:00', 0),
  ('month','2025-11-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 175, '2025-11-30 23:40:00', 0),
  ('month','2025-11-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 582, '2025-11-30 23:35:00', 0),
  ('month','2025-11-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 235, '2025-11-30 23:30:00', 0),
  ('month','2025-11-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 690, '2025-11-30 23:25:00', 0),
  ('month','2025-11-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 322, '2025-11-30 23:20:00', 0),
  ('month','2025-11-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 218, '2025-11-30 23:15:00', 0),
  ('month','2025-11-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 120, '2025-11-30 23:10:00', 0),
  ('month','2025-11-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 660, '2025-11-30 23:05:00', 0),
  ('month','2025-11-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 280, '2025-11-30 23:00:00', 0),
  ('month','2025-11-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 495, '2025-11-30 22:55:00', 0),
  ('month','2025-11-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 145, '2025-11-30 22:50:00', 0),

  -- ---- 2025-12 ----
  ('month','2025-12-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 395, '2025-12-31 23:45:00', 0),
  ('month','2025-12-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 148, '2025-12-31 23:40:00', 0),
  ('month','2025-12-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 518, '2025-12-31 23:35:00', 0),
  ('month','2025-12-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 205, '2025-12-31 23:30:00', 0),
  ('month','2025-12-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 620, '2025-12-31 23:25:00', 0),
  ('month','2025-12-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 282, '2025-12-31 23:20:00', 0),
  ('month','2025-12-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 192, '2025-12-31 23:15:00', 0),
  ('month','2025-12-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 105, '2025-12-31 23:10:00', 0),
  ('month','2025-12-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 588, '2025-12-31 23:05:00', 0),
  ('month','2025-12-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 250, '2025-12-31 23:00:00', 0),
  ('month','2025-12-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 440, '2025-12-31 22:55:00', 0),
  ('month','2025-12-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 125, '2025-12-31 22:50:00', 0),

  -- ---- 2026-01 ----
  ('month','2026-01-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 478, '2026-01-31 23:45:00', 0),
  ('month','2026-01-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 188, '2026-01-31 23:40:00', 0),
  ('month','2026-01-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 622, '2026-01-31 23:35:00', 0),
  ('month','2026-01-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 252, '2026-01-31 23:30:00', 0),
  ('month','2026-01-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 735, '2026-01-31 23:25:00', 0),
  ('month','2026-01-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 342, '2026-01-31 23:20:00', 0),
  ('month','2026-01-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 235, '2026-01-31 23:15:00', 0),
  ('month','2026-01-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 132, '2026-01-31 23:10:00', 0),
  ('month','2026-01-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 705, '2026-01-31 23:05:00', 0),
  ('month','2026-01-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 302, '2026-01-31 23:00:00', 0),
  ('month','2026-01-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 535, '2026-01-31 22:55:00', 0),
  ('month','2026-01-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 162, '2026-01-31 22:50:00', 0),

  -- ---- 2026-02 ----
  ('month','2026-02-01', 1001,'Kapiri Urban Clinic',      5001,'Central Reference Lab',    100,'Central Province',  1, 462, '2026-02-28 23:45:00', 0),
  ('month','2026-02-01', 1002,'Kapiri Rural Health Post', 5001,'Central Reference Lab',    100,'Central Province',  1, 180, '2026-02-28 23:40:00', 0),
  ('month','2026-02-01', 1003,'Mkushi District Hospital', 5001,'Central Reference Lab',    100,'Central Province',  1, 608, '2026-02-28 23:35:00', 0),
  ('month','2026-02-01', 1004,'Mkushi Health Centre',     5004,'National VL Reference Lab',100,'Central Province',  1, 245, '2026-02-28 23:30:00', 0),
  ('month','2026-02-01', 2001,'Kasama General Hospital',  5002,'Northern Provincial Lab',  200,'Northern Province', 1, 718, '2026-02-28 23:25:00', 0),
  ('month','2026-02-01', 2002,'Kasama Urban Clinic',      5002,'Northern Provincial Lab',  200,'Northern Province', 1, 335, '2026-02-28 23:20:00', 0),
  ('month','2026-02-01', 2003,'Mpulungu Health Centre',   5002,'Northern Provincial Lab',  200,'Northern Province', 1, 228, '2026-02-28 23:15:00', 0),
  ('month','2026-02-01', 2004,'Mpulungu Rural Clinic',    5002,'Northern Provincial Lab',  200,'Northern Province', 1, 125, '2026-02-28 23:10:00', 0),
  ('month','2026-02-01', 3001,'Livingstone Central Hosp', 5003,'Southern Provincial Lab',  300,'Southern Province', 1, 690, '2026-02-28 23:05:00', 0),
  ('month','2026-02-01', 3002,'Livingstone Clinic',       5003,'Southern Provincial Lab',  300,'Southern Province', 1, 295, '2026-02-28 23:00:00', 0),
  ('month','2026-02-01', 3003,'Choma District Hospital',  5003,'Southern Provincial Lab',  300,'Southern Province', 1, 520, '2026-02-28 22:55:00', 0),
  ('month','2026-02-01', 3004,'Choma Health Post',        5004,'National VL Reference Lab',300,'Southern Province', 1, 155, '2026-02-28 22:50:00', 0);


-- ----------------------------------------------------------
-- vl_agg_result_category
-- ----------------------------------------------------------
-- Categories: suppressed, not suppressed, rejected, invalid, failed.
-- ~85% suppressed among those with results, realistic program data.
-- Rows are at the country level (facility_id/lab_id NULL) for
-- simplicity; per-facility breakdowns are in vl_agg_volume_org.

INSERT INTO `vl_agg_result_category`
  (`period_type`, `period_start_date`, `vl_result_category`,
   `facility_id`, `lab_id`, `vlsm_country_id`,
   `n_samples`, `source_max_last_modified`, `suppression_applied`)
VALUES
  -- 2025-03
  ('month','2025-03-01','suppressed',     NULL, NULL, 1, 3265, '2025-03-31 23:45:00', 0),
  ('month','2025-03-01','not suppressed',  NULL, NULL, 1,  420, '2025-03-31 23:40:00', 0),
  ('month','2025-03-01','rejected',       NULL, NULL, 1,  156, '2025-03-31 23:35:00', 0),
  ('month','2025-03-01','invalid',        NULL, NULL, 1,   38, '2025-03-31 23:30:00', 0),
  ('month','2025-03-01','failed',         NULL, NULL, 1,   22, '2025-03-31 23:25:00', 0),
  -- 2025-04
  ('month','2025-04-01','suppressed',     NULL, NULL, 1, 3362, '2025-04-30 23:45:00', 0),
  ('month','2025-04-01','not suppressed',  NULL, NULL, 1,  435, '2025-04-30 23:40:00', 0),
  ('month','2025-04-01','rejected',       NULL, NULL, 1,  162, '2025-04-30 23:35:00', 0),
  ('month','2025-04-01','invalid',        NULL, NULL, 1,   42, '2025-04-30 23:30:00', 0),
  ('month','2025-04-01','failed',         NULL, NULL, 1,   18, '2025-04-30 23:25:00', 0),
  -- 2025-05
  ('month','2025-05-01','suppressed',     NULL, NULL, 1, 3490, '2025-05-31 23:45:00', 0),
  ('month','2025-05-01','not suppressed',  NULL, NULL, 1,  452, '2025-05-31 23:40:00', 0),
  ('month','2025-05-01','rejected',       NULL, NULL, 1,  148, '2025-05-31 23:35:00', 0),
  ('month','2025-05-01','invalid',        NULL, NULL, 1,   35, '2025-05-31 23:30:00', 0),
  ('month','2025-05-01','failed',         NULL, NULL, 1,   25, '2025-05-31 23:25:00', 0),
  -- 2025-06
  ('month','2025-06-01','suppressed',     NULL, NULL, 1, 3596, '2025-06-30 23:45:00', 0),
  ('month','2025-06-01','not suppressed',  NULL, NULL, 1,  468, '2025-06-30 23:40:00', 0),
  ('month','2025-06-01','rejected',       NULL, NULL, 1,  171, '2025-06-30 23:35:00', 0),
  ('month','2025-06-01','invalid',        NULL, NULL, 1,   40, '2025-06-30 23:30:00', 0),
  ('month','2025-06-01','failed',         NULL, NULL, 1,   20, '2025-06-30 23:25:00', 0),
  -- 2025-07
  ('month','2025-07-01','suppressed',     NULL, NULL, 1, 3722, '2025-07-31 23:45:00', 0),
  ('month','2025-07-01','not suppressed',  NULL, NULL, 1,  485, '2025-07-31 23:40:00', 0),
  ('month','2025-07-01','rejected',       NULL, NULL, 1,  180, '2025-07-31 23:35:00', 0),
  ('month','2025-07-01','invalid',        NULL, NULL, 1,   45, '2025-07-31 23:30:00', 0),
  ('month','2025-07-01','failed',         NULL, NULL, 1,   28, '2025-07-31 23:25:00', 0),
  -- 2025-08
  ('month','2025-08-01','suppressed',     NULL, NULL, 1, 3835, '2025-08-31 23:45:00', 0),
  ('month','2025-08-01','not suppressed',  NULL, NULL, 1,  502, '2025-08-31 23:40:00', 0),
  ('month','2025-08-01','rejected',       NULL, NULL, 1,  188, '2025-08-31 23:35:00', 0),
  ('month','2025-08-01','invalid',        NULL, NULL, 1,   48, '2025-08-31 23:30:00', 0),
  ('month','2025-08-01','failed',         NULL, NULL, 1,   30, '2025-08-31 23:25:00', 0),
  -- 2025-09
  ('month','2025-09-01','suppressed',     NULL, NULL, 1, 3642, '2025-09-30 23:45:00', 0),
  ('month','2025-09-01','not suppressed',  NULL, NULL, 1,  478, '2025-09-30 23:40:00', 0),
  ('month','2025-09-01','rejected',       NULL, NULL, 1,  165, '2025-09-30 23:35:00', 0),
  ('month','2025-09-01','invalid',        NULL, NULL, 1,   42, '2025-09-30 23:30:00', 0),
  ('month','2025-09-01','failed',         NULL, NULL, 1,   22, '2025-09-30 23:25:00', 0),
  -- 2025-10
  ('month','2025-10-01','suppressed',     NULL, NULL, 1, 3927, '2025-10-31 23:45:00', 0),
  ('month','2025-10-01','not suppressed',  NULL, NULL, 1,  515, '2025-10-31 23:40:00', 0),
  ('month','2025-10-01','rejected',       NULL, NULL, 1,  192, '2025-10-31 23:35:00', 0),
  ('month','2025-10-01','invalid',        NULL, NULL, 1,   50, '2025-10-31 23:30:00', 0),
  ('month','2025-10-01','failed',         NULL, NULL, 1,   32, '2025-10-31 23:25:00', 0),
  -- 2025-11
  ('month','2025-11-01','suppressed',     NULL, NULL, 1, 3749, '2025-11-30 23:45:00', 0),
  ('month','2025-11-01','not suppressed',  NULL, NULL, 1,  495, '2025-11-30 23:40:00', 0),
  ('month','2025-11-01','rejected',       NULL, NULL, 1,  175, '2025-11-30 23:35:00', 0),
  ('month','2025-11-01','invalid',        NULL, NULL, 1,   45, '2025-11-30 23:30:00', 0),
  ('month','2025-11-01','failed',         NULL, NULL, 1,   25, '2025-11-30 23:25:00', 0),
  -- 2025-12
  ('month','2025-12-01','suppressed',     NULL, NULL, 1, 3332, '2025-12-31 23:45:00', 0),
  ('month','2025-12-01','not suppressed',  NULL, NULL, 1,  432, '2025-12-31 23:40:00', 0),
  ('month','2025-12-01','rejected',       NULL, NULL, 1,  158, '2025-12-31 23:35:00', 0),
  ('month','2025-12-01','invalid',        NULL, NULL, 1,   38, '2025-12-31 23:30:00', 0),
  ('month','2025-12-01','failed',         NULL, NULL, 1,   20, '2025-12-31 23:25:00', 0),
  -- 2026-01
  ('month','2026-01-01','suppressed',     NULL, NULL, 1, 4038, '2026-01-31 23:45:00', 0),
  ('month','2026-01-01','not suppressed',  NULL, NULL, 1,  530, '2026-01-31 23:40:00', 0),
  ('month','2026-01-01','rejected',       NULL, NULL, 1,  198, '2026-01-31 23:35:00', 0),
  ('month','2026-01-01','invalid',        NULL, NULL, 1,   52, '2026-01-31 23:30:00', 0),
  ('month','2026-01-01','failed',         NULL, NULL, 1,   28, '2026-01-31 23:25:00', 0),
  -- 2026-02
  ('month','2026-02-01','suppressed',     NULL, NULL, 1, 3893, '2026-02-28 23:45:00', 0),
  ('month','2026-02-01','not suppressed',  NULL, NULL, 1,  518, '2026-02-28 23:40:00', 0),
  ('month','2026-02-01','rejected',       NULL, NULL, 1,  185, '2026-02-28 23:35:00', 0),
  ('month','2026-02-01','invalid',        NULL, NULL, 1,   48, '2026-02-28 23:30:00', 0),
  ('month','2026-02-01','failed',         NULL, NULL, 1,   24, '2026-02-28 23:25:00', 0);


-- ----------------------------------------------------------
-- vl_agg_tat
-- ----------------------------------------------------------
-- 3 common TAT metric pairs, monthly, per lab.
-- Realistic TAT values:
--   collection_to_receipt_lab: ~24-72 hours median
--   receipt_lab_to_testing:    ~4-24 hours median
--   testing_to_approval:       ~1-8 hours median

INSERT INTO `vl_agg_tat`
  (`period_type`, `period_start_date`, `tat_metric`, `tat_start_field`, `tat_end_field`,
   `facility_id`, `lab_id`, `vlsm_country_id`,
   `n_samples`, `avg_hours`, `p50_hours`, `p90_hours`, `p95_hours`, `min_hours`, `max_hours`,
   `source_max_last_modified`, `suppression_applied`)
VALUES
  -- =====================================================
  -- tat_collection_to_receipt_lab — per lab, 12 months
  -- =====================================================
  -- Lab 5001 (Central Reference Lab)
  ('month','2025-03-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1245, 52.30, 48.50, 96.20, 120.50,  4.20, 192.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1298, 50.80, 46.20, 92.10, 118.30,  3.80, 185.50, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1333, 49.50, 45.00, 90.80, 115.20,  4.50, 178.20, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1377, 48.20, 44.30, 88.50, 112.80,  3.50, 172.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1420, 47.10, 43.50, 86.30, 110.50,  4.00, 168.50, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1463, 46.50, 42.80, 85.00, 108.20,  3.20, 165.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1382, 47.80, 44.00, 87.50, 111.30,  4.10, 170.50, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1497, 45.90, 42.00, 84.20, 106.80,  3.00, 162.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1437, 46.80, 43.20, 85.80, 109.50,  3.80, 168.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1266, 50.20, 47.00, 93.50, 119.80,  5.00, 188.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1540, 44.80, 41.50, 82.30, 104.50,  2.80, 158.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5001, 1, 1495, 45.50, 42.00, 83.80, 106.20,  3.20, 162.50, '2026-02-28 23:45:00', 0),

  -- Lab 5002 (Northern Provincial Lab) — slightly longer TAT (more remote)
  ('month','2025-03-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1160, 62.50, 58.00, 112.30, 140.20,  6.50, 220.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1208, 60.80, 56.50, 108.80, 136.50,  5.80, 215.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1250, 59.20, 55.00, 106.50, 132.80,  6.20, 210.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1287, 58.00, 54.00, 104.20, 130.50,  5.50, 205.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1328, 57.20, 53.50, 102.80, 128.20,  6.00, 200.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1365, 56.50, 52.80, 101.00, 126.50,  5.20, 198.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1293, 58.80, 55.00, 105.50, 131.80,  6.50, 208.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1406, 55.80, 52.00, 100.20, 124.80,  5.00, 195.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1350, 57.00, 53.00, 102.00, 127.50,  5.50, 200.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1199, 61.50, 57.50, 110.00, 138.00,  7.00, 218.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1444, 54.80, 51.20, 98.50, 122.80,  4.80, 190.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5002, 1, 1406, 55.50, 52.00, 100.00, 124.50,  5.20, 195.00, '2026-02-28 23:45:00', 0),

  -- Lab 5003 (Southern Provincial Lab)
  ('month','2025-03-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1255, 55.20, 50.80, 98.50, 122.00,  5.00, 195.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1295, 53.80, 49.50, 96.20, 119.80,  4.50, 190.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1335, 52.50, 48.20, 94.00, 117.50,  5.20, 185.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1374, 51.80, 47.50, 92.50, 115.20,  4.80, 182.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1416, 50.50, 46.50, 90.80, 113.00,  5.50, 178.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1455, 49.80, 45.80, 89.20, 111.50,  4.20, 175.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1383, 51.50, 47.20, 91.80, 114.50,  5.00, 180.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1492, 48.80, 45.00, 88.00, 109.80,  4.00, 172.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1435, 50.20, 46.00, 90.00, 112.00,  4.50, 176.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1278, 54.00, 50.00, 96.80, 120.50,  5.80, 192.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1542, 47.50, 44.00, 86.50, 108.00,  3.80, 168.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5003, 1, 1505, 48.20, 44.80, 87.50, 109.50,  4.20, 172.00, '2026-02-28 23:45:00', 0),

  -- Lab 5004 (National VL Reference Lab) — receives overflow, moderate TAT
  ('month','2025-03-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 318, 68.50, 64.00, 120.50, 148.00,  8.00, 240.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 338, 66.80, 62.50, 118.00, 145.50,  7.50, 235.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 347, 65.20, 61.00, 116.50, 142.80,  8.20, 230.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 360, 64.00, 60.00, 114.00, 140.50,  7.00, 225.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 375, 63.20, 59.50, 112.50, 138.20,  7.80, 222.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 388, 62.50, 58.80, 110.80, 136.50,  6.50, 218.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 360, 64.50, 60.50, 114.80, 141.00,  7.50, 226.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 400, 61.80, 58.00, 110.00, 135.00,  6.20, 215.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 380, 63.00, 59.00, 112.00, 137.50,  7.00, 220.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 330, 67.50, 63.50, 119.00, 146.50,  8.50, 238.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 414, 60.50, 57.00, 108.50, 133.00,  6.00, 210.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_collection_to_receipt_lab','sample_collection_date','sample_received_at_lab_datetime', NULL, 5004, 1, 400, 61.20, 57.50, 109.00, 134.50,  6.50, 214.00, '2026-02-28 23:45:00', 0),

  -- =====================================================
  -- tat_receipt_lab_to_testing — per lab, 12 months
  -- =====================================================
  -- Lab 5001
  ('month','2025-03-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1230, 14.80, 12.50, 28.30, 36.20, 0.50, 72.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1282, 14.20, 12.00, 27.50, 35.00, 0.40, 68.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1318, 13.80, 11.50, 26.80, 34.20, 0.50, 65.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1360, 13.50, 11.20, 26.00, 33.50, 0.30, 62.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1402, 13.20, 10.80, 25.50, 32.80, 0.40, 60.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1445, 12.80, 10.50, 24.80, 32.00, 0.30, 58.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1365, 13.50, 11.00, 26.20, 33.80, 0.50, 64.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1478, 12.50, 10.20, 24.00, 31.00, 0.30, 56.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1418, 13.00, 10.80, 25.20, 32.50, 0.40, 60.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1248, 15.20, 13.00, 29.50, 37.80, 0.60, 75.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1520, 12.00, 10.00, 23.50, 30.20, 0.30, 54.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5001, 1, 1478, 12.30, 10.20, 24.00, 31.00, 0.40, 56.00, '2026-02-28 23:45:00', 0),

  -- Lab 5002
  ('month','2025-03-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1142, 18.50, 16.00, 35.80, 45.00, 0.80, 88.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1190, 17.80, 15.50, 34.50, 43.80, 0.70, 85.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1230, 17.20, 15.00, 33.50, 42.50, 0.80, 82.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1268, 16.80, 14.50, 32.80, 41.20, 0.60, 80.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1308, 16.20, 14.00, 31.50, 40.00, 0.70, 78.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1345, 15.80, 13.50, 30.80, 39.20, 0.60, 75.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1272, 17.00, 14.80, 33.00, 42.00, 0.80, 82.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1385, 15.20, 13.00, 30.00, 38.50, 0.50, 72.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1328, 16.00, 13.80, 31.50, 40.00, 0.70, 76.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1178, 19.00, 16.50, 36.50, 46.00, 1.00, 90.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1422, 14.80, 12.80, 29.50, 37.50, 0.50, 70.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5002, 1, 1385, 15.00, 13.00, 30.00, 38.00, 0.60, 72.00, '2026-02-28 23:45:00', 0),

  -- Lab 5003
  ('month','2025-03-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1238, 16.20, 14.00, 32.00, 40.50, 0.60, 80.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1278, 15.80, 13.50, 31.20, 39.80, 0.50, 78.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1318, 15.20, 13.00, 30.50, 38.80, 0.60, 76.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1355, 14.80, 12.80, 29.80, 38.00, 0.50, 74.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1398, 14.50, 12.50, 29.00, 37.00, 0.50, 72.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1438, 14.00, 12.00, 28.50, 36.50, 0.40, 70.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1365, 14.80, 12.80, 29.50, 37.80, 0.60, 74.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1475, 13.50, 11.80, 27.80, 35.50, 0.40, 68.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1418, 14.20, 12.20, 28.80, 36.80, 0.50, 71.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1260, 16.50, 14.50, 32.50, 41.00, 0.80, 82.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1525, 13.20, 11.50, 27.00, 34.80, 0.30, 66.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5003, 1, 1488, 13.50, 11.80, 27.50, 35.50, 0.40, 68.00, '2026-02-28 23:45:00', 0),

  -- Lab 5004
  ('month','2025-03-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 310, 20.50, 18.00, 38.50, 48.00, 1.00, 96.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 330, 19.80, 17.50, 37.00, 46.50, 0.80, 92.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 340, 19.20, 17.00, 36.00, 45.00, 1.00, 88.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 352, 18.80, 16.50, 35.00, 44.00, 0.80, 86.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 368, 18.20, 16.00, 34.20, 43.00, 0.70, 84.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 380, 17.80, 15.50, 33.50, 42.00, 0.60, 82.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 352, 19.00, 16.80, 35.50, 44.50, 0.90, 86.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 392, 17.20, 15.00, 32.80, 41.00, 0.50, 80.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 372, 18.00, 15.80, 34.00, 43.00, 0.70, 83.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 322, 21.00, 18.50, 39.50, 49.00, 1.20, 98.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 406, 16.80, 14.80, 32.00, 40.00, 0.50, 78.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_receipt_lab_to_testing','sample_received_at_lab_datetime','sample_tested_datetime', NULL, 5004, 1, 392, 17.00, 15.00, 32.50, 40.80, 0.60, 80.00, '2026-02-28 23:45:00', 0),

  -- =====================================================
  -- tat_testing_to_approval — per lab, 12 months
  -- =====================================================
  -- Lab 5001
  ('month','2025-03-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1215, 4.80, 3.50, 10.20, 14.50, 0.10, 36.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1268, 4.50, 3.20, 9.80, 13.80, 0.10, 34.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1302, 4.30, 3.00, 9.50, 13.20, 0.10, 32.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1345, 4.10, 2.80, 9.00, 12.80, 0.10, 30.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1388, 3.90, 2.60, 8.50, 12.20, 0.10, 28.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1430, 3.80, 2.50, 8.20, 11.80, 0.10, 26.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1350, 4.20, 3.00, 9.00, 12.80, 0.10, 30.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1462, 3.60, 2.40, 8.00, 11.50, 0.10, 25.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1405, 3.80, 2.60, 8.50, 12.00, 0.10, 27.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1235, 5.20, 3.80, 11.00, 15.50, 0.20, 38.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1505, 3.50, 2.30, 7.80, 11.00, 0.10, 24.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5001, 1, 1462, 3.60, 2.50, 8.00, 11.50, 0.10, 25.00, '2026-02-28 23:45:00', 0),

  -- Lab 5002
  ('month','2025-03-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1125, 6.50, 5.00, 14.20, 18.50, 0.20, 48.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1172, 6.20, 4.80, 13.50, 17.80, 0.20, 45.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1212, 5.80, 4.50, 13.00, 17.00, 0.20, 42.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1250, 5.50, 4.20, 12.50, 16.50, 0.20, 40.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1290, 5.30, 4.00, 12.00, 16.00, 0.10, 38.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1328, 5.10, 3.80, 11.50, 15.50, 0.10, 36.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1255, 5.80, 4.50, 12.80, 16.80, 0.20, 42.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1368, 4.80, 3.60, 11.00, 15.00, 0.10, 34.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1310, 5.20, 4.00, 11.80, 15.80, 0.20, 38.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1162, 6.80, 5.20, 14.80, 19.50, 0.30, 50.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1405, 4.60, 3.50, 10.50, 14.50, 0.10, 32.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5002, 1, 1368, 4.80, 3.60, 11.00, 15.00, 0.10, 34.00, '2026-02-28 23:45:00', 0),

  -- Lab 5003
  ('month','2025-03-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1222, 5.50, 4.00, 12.00, 16.00, 0.10, 40.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1262, 5.20, 3.80, 11.50, 15.50, 0.10, 38.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1302, 5.00, 3.50, 11.00, 15.00, 0.10, 36.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1340, 4.80, 3.30, 10.50, 14.50, 0.10, 34.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1382, 4.50, 3.10, 10.00, 14.00, 0.10, 32.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1422, 4.30, 3.00, 9.80, 13.50, 0.10, 30.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1348, 4.80, 3.40, 10.50, 14.50, 0.10, 34.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1458, 4.00, 2.80, 9.50, 13.00, 0.10, 28.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1402, 4.30, 3.00, 9.80, 13.50, 0.10, 30.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1245, 5.80, 4.20, 12.50, 16.80, 0.20, 42.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1508, 3.80, 2.60, 9.00, 12.50, 0.10, 26.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5003, 1, 1472, 4.00, 2.80, 9.50, 13.00, 0.10, 28.00, '2026-02-28 23:45:00', 0),

  -- Lab 5004
  ('month','2025-03-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 302, 7.80, 6.00, 16.50, 22.00, 0.30, 52.00, '2025-03-31 23:45:00', 0),
  ('month','2025-04-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 322, 7.50, 5.80, 16.00, 21.00, 0.30, 50.00, '2025-04-30 23:45:00', 0),
  ('month','2025-05-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 332, 7.20, 5.50, 15.50, 20.50, 0.20, 48.00, '2025-05-31 23:45:00', 0),
  ('month','2025-06-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 345, 7.00, 5.30, 15.00, 20.00, 0.20, 46.00, '2025-06-30 23:45:00', 0),
  ('month','2025-07-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 360, 6.80, 5.00, 14.50, 19.50, 0.20, 44.00, '2025-07-31 23:45:00', 0),
  ('month','2025-08-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 372, 6.50, 4.80, 14.00, 18.80, 0.20, 42.00, '2025-08-31 23:45:00', 0),
  ('month','2025-09-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 345, 7.00, 5.20, 15.00, 20.00, 0.30, 46.00, '2025-09-30 23:45:00', 0),
  ('month','2025-10-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 385, 6.20, 4.60, 13.50, 18.00, 0.20, 40.00, '2025-10-31 23:45:00', 0),
  ('month','2025-11-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 365, 6.50, 4.80, 14.00, 19.00, 0.20, 42.00, '2025-11-30 23:45:00', 0),
  ('month','2025-12-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 315, 8.00, 6.20, 17.00, 22.50, 0.40, 54.00, '2025-12-31 23:45:00', 0),
  ('month','2026-01-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 398, 6.00, 4.50, 13.00, 17.50, 0.20, 38.00, '2026-01-31 23:45:00', 0),
  ('month','2026-02-01','tat_testing_to_approval','sample_tested_datetime','result_approved_datetime', NULL, 5004, 1, 385, 6.20, 4.60, 13.50, 18.00, 0.20, 40.00, '2026-02-28 23:45:00', 0);


-- ----------------------------------------------------------
-- etl_refresh_log — record that seed data was loaded
-- ----------------------------------------------------------
INSERT INTO `etl_refresh_log`
  (`table_name`, `refresh_type`, `date_range_start`, `date_range_end`,
   `started_at`, `completed_at`, `status`)
VALUES
  ('vl_agg_volume_status',   'full', '2025-03-01', '2026-02-28', NOW(), NOW(), 'completed'),
  ('vl_agg_volume_org',      'full', '2025-03-01', '2026-02-28', NOW(), NOW(), 'completed'),
  ('vl_agg_result_category', 'full', '2025-03-01', '2026-02-28', NOW(), NOW(), 'completed'),
  ('vl_agg_tat',             'full', '2025-03-01', '2026-02-28', NOW(), NOW(), 'completed'),
  ('ref_sample_status',      'full', NULL,          NULL,         NOW(), NOW(), 'completed'),
  ('ref_geographical_divisions','full', NULL,       NULL,         NOW(), NOW(), 'completed'),
  ('ref_facilities',         'full', NULL,          NULL,         NOW(), NOW(), 'completed'),
  ('ref_vl_rejection_reasons','full', NULL,         NULL,         NOW(), NOW(), 'completed'),
  ('ref_vl_test_reasons',    'full', NULL,          NULL,         NOW(), NOW(), 'completed'),
  ('ref_vl_sample_types',    'full', NULL,          NULL,         NOW(), NOW(), 'completed');
