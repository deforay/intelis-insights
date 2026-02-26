<?php
// config/field-guide.php
declare(strict_types=1);

return [
    // Domain-specific terminology mapping
    'terminology_mapping' => [
        'vl|viral load|hiv vl'          => 'form_vl',
        'eid|infant|early infant|dna'   => 'form_eid',
        'covid|coronavirus|sars-cov-2'  => 'form_covid19',
        'tb|tuberculosis|xpert'         => 'form_tb',
        'cd4'                           => 'form_cd4',
        'lab name|testing lab'          => 'facility_details.facility_name',
        'sample code|sample codes|sample id' => 'sample_code',
        'facility name|lab name|clinic name' => 'facility_name',
        'result|test result|test outcome' => 'result',
        'viral load|vl count|vl result' => 'result_value_absolute',
        'high viral load|high vl|elevated viral load|not suppressed' => 'vl_result_category = "not suppressed"',
        'undetectable|suppressed|low viral load' => 'vl_result_category = "suppressed"',
        'last modified|recently updated|updated' => 'last_modified_datetime',
        'collection date|collected|when collected' => 'sample_collection_date',
        'test date|tested|when tested' => 'sample_tested_datetime',
        'rejected|rejection|rejected sample' => 'IFNULL(is_sample_rejected, "no") = "yes"',
        'batch|batch code|batch number' => 'batch_code',
        'requesting facility|clinic|collection site' => 'facility_id',
        'testing lab|lab|laboratory' => 'lab_id',
        'patient sex|gender|patient gender' => 'patient_gender',
        'pregnant|pregnancy' => 'IFNULL(is_patient_pregnant, "no") = "yes"',
        'breastfeeding|lactating' => 'IFNULL(is_patient_breastfeeding, "no") = "yes"',
        'tuberculosis|tb|active tb' => 'IFNULL(patient_has_active_tb, "no") = "yes"'
    ],

    // Clinical thresholds and interpretations
    'clinical_thresholds' => [
        'vl' => [
            'description' => 'Viral Load test thresholds and categories',
            'thresholds' => [
                'suppressed' => [
                    'condition' => 'vl_result_category = "suppressed"',
                    'description' => 'VL is suppressed/undetectable',
                    'clinical_meaning' => 'Treatment is working effectively'
                ],
                'not_suppressed' => [
                    'condition' => 'vl_result_category = "not suppressed"',
                    'description' => 'VL is detectable/elevated',
                    'clinical_meaning' => 'May indicate treatment failure or adherence issues'
                ],
                'high_vl_numeric' => [
                    'condition' => 'result_value_absolute > 1000',
                    'description' => 'High viral load (>1000 copies/mL)',
                    'clinical_meaning' => 'Significant viral replication'
                ],
                'low_vl_numeric' => [
                    'condition' => 'result_value_absolute < 50',
                    'description' => 'Low/suppressed viral load (<50 copies/mL)',
                    'clinical_meaning' => 'Good viral suppression'
                ]
            ],
            'default_filters' => [
                'valid_results' => 'result IS NOT NULL OR result_value_absolute IS NOT NULL',
                'not_rejected' => 'IFNULL(is_sample_rejected, "no") = "no"',
                'completed_tests' => 'sample_tested_datetime IS NOT NULL'
            ]
        ],
        'covid19' => [
            'description' => 'COVID-19 test interpretations',
            'categories' => [
                'positive' => 'result = "Positive"',
                'negative' => 'result = "Negative"',
                'inconclusive' => 'result IN ("Inconclusive", "Invalid", "Failed")'
            ]
        ]
    ],

    // Test type specific logic and defaults
    'test_type_logic' => [
        'vl' => [
            'table' => 'form_vl',
            'lab_id_col' => 'lab_id',
            'primary_result_column' => 'result_value_absolute',
            'text_result_column' => 'result',
            'category_column' => 'vl_result_category',
            'date_tested_column' => 'sample_tested_datetime',
            'date_collected_column' => 'sample_collection_date',
            'sample_id_column' => 'sample_code',
            'default_description' => 'Viral Load tests for HIV monitoring',
            'display_lab_name' => 'facility_details.facility_name',
            'common_groupings' => [
                'by_suppression_status' => 'vl_result_category',
                'by_facility' => 'facility_id',
                'by_month' => 'DATE_FORMAT(sample_tested_datetime, "%Y-%m")',
                'by_gender' => 'patient_gender'
            ]
        ],
        'covid19' => [
            'table' => 'form_covid19',
            'lab_id_col' => 'lab_id',
            'primary_result_column' => 'result',
            'date_tested_column' => 'sample_tested_datetime',
            'sample_id_column' => 'sample_code',
            'default_description' => 'COVID-19 diagnostic tests',
            'display_lab_name' => 'facility_details.facility_name',
        ],
        'eid' => [
            'table' => 'form_eid',
            'lab_id_col' => 'lab_id',
            'primary_result_column' => 'result',
            'date_tested_column' => 'sample_tested_datetime',
            'sample_id_column' => 'sample_code',
            'default_description' => 'Early Infant Diagnosis or EID for HIV',
            'display_lab_name' => 'facility_details.facility_name',
        ],
        // Tuberculosis
        'tb' => [
            'table' => 'form_tb',
            'lab_id_col' => 'lab_id',
            'date_tested_col' => 'sample_tested_datetime',
            'date_tested_column' => 'sample_collection_date',
            'display_lab_name' => 'facility_details.facility_name',
        ],
        // CD4
        'cd4' => [
            'table' => 'form_cd4',
            'lab_id_col' => 'lab_id',
            'date_tested_column' => 'sample_tested_datetime',
            'date_collected_col' => 'sample_collection_date',
            'display_lab_name' => 'facility_details.facility_name',
        ],
    ],

    // Column semantics and descriptions
    'column_semantics' => [
        'form_vl' => [
            'patient_art_no' => 'Patient ART number/identifier. Never select or return this column for privacy',
            'sample_code' => 'Human-readable sample identifier for display. Generated at lab in LIS. Possible duplicates across different testing labs',
            'remote_sample_code' => 'Sample ID from Sample Tracking System (STS). Usually different from sample_code. Indicates request origin',
            'implementing_partner' => 'NGO or partner supporting the facility. Links to r_implementation_partners table',
            'current_regimen' => 'Patient antiretroviral therapy (ART) regimen at sample collection time',
            'patient_gender' => 'Patient sex. Always refer to as "sex" not "gender" in queries and results',
            'is_patient_pregnant' => 'Pregnancy status. Values: "yes"/"no". Use IFNULL as older records may be NULL',
            'is_patient_breastfeeding' => 'Breastfeeding status. Values: "yes"/"no". Use IFNULL as older records may be NULL',
            'date_of_initiation_of_current_regimen' => 'Date patient started current ART regimen',
            'vl_sample_id' => 'Internal database ID - avoid in user queries',
            'facility_id' => 'Links to facility_details table. The requesting facility/clinic which collected the sample. Never show the facility_id directly, always join to facility_details and show facility_name or facility_code',
            'lab_id' => 'JOIN with facility_details.facility_id. The testing laboratory where sample was processed/tested. Never show the lab_id directly, always join to facility_details and show facility_name or facility_code',
            'result_value_absolute' => 'Numeric VL count for comparisons (copies/mL). Use for threshold-based filtering',
            'result' => 'Text result (number or text like "Not Detected"). Rarely used for filtering unless specific text matching needed',
            'vl_result_category' => 'Clinical categorization: "suppressed" or "not suppressed". Preferred for clinical queries. Not to be used for joins.',
            'is_sample_rejected' => 'Sample rejection status. Values: "yes"/"no". Use IFNULL as older records may be NULL. IFNULL(is_sample_rejected, "no") = "yes"',
            'specimen_type' => 'Sample type (Plasma, DBS, Whole Blood). Links to r_vl_sample_type table',
            'reason_for_sample_rejection' => 'Rejection reason if applicable',
            'rejection_on' => 'Date of sample rejection if applicable',
            'sample_received_at_lab_datetime' => 'Lab receipt timestamp',
            'sample_collection_date' => 'Patient sample collection date. Different from request_created_datetime',
            'sample_dispatched_datetime' => 'Dispatch timestamp from requesting facility to lab',
            'patient_has_active_tb' => 'Active tuberculosis status. Values: "yes"/"no". Use IFNULL as older records may be NULL',
            'sample_tested_datetime' => 'Lab testing timestamp. Primary date for temporal analysis',
            'last_modified_datetime' => 'Record last update timestamp',
            'result_value_log' => 'Log-transformed VL result for statistical analysis',
            'result_status' => 'Test result status. Links to r_sample_status table',
            'tested_by' => 'Testing technician. Links to user_details table via user_id',
            'approved_by' => 'Approving supervisor. Links to user_details table via user_id',
            'reviewed_by' => 'Reviewing supervisor. Links to user_details table via user_id',
            'revised_by' => 'Revising supervisor. Links to user_details table via user_id',
            'source_of_request' => 'Request origin: LIS, STS, or API (EMR/DHIS2). Use to filter by source system',
            'system_patient_code' => 'Internal patient identifier - NEVER SELECT for privacy',
            'request_created_datetime' => 'System request creation timestamp',
            'last_modified_by' => 'Last modifier user ID. Links to user_details table via user_id',
            'instrument_id' => 'Testing machine/analyzer/instrument identifier. Links to instrument_machines table or instruments table. Never return instrument_id, always make sure you return the instrument_machines.config_machine_name or instruments.machine_name',
            'vl_test_platform' => 'Testing machine/analyzer/instrument name. DO NOT USE THIS FOR JOINS. Always join on instrument_id to get the machine name. Only use this column as fallback to show name if instrument_id join not possible',
        ],

        'facility_details' => [
            'facility_id' => 'Primary facility identifier',
            'facility_name' => 'Facility/clinic/lab name',
            'facility_code' => 'Facility code identifier - prefer over facility_name for identification',
            'facility_state_id' => 'State|Province. Links to geographical_divisions where geographical_divisions.geo_id = facility_details.facility_state_id',
            'facility_district_id' => 'District|County. Links to geographical_divisions where geographical_divisions.geo_id = facility_details.facility_district_id',
            'facility_type' => 'Type: 1=collection site/clinic, 2=testing lab',
        ],

        'user_details' => [
            'user_id' => 'Primary user identifier',
            'user_name' => 'System username',
            'first_name' => 'User first name',
            'last_name' => 'User last name',
            'role_id' => 'User role. Links to roles table'
        ],

        'batch_details' => [
            'batch_id' => 'Primary batch identifier',
            'batch_code' => 'Human-readable batch code',
            'machine_used' => 'Testing machine/analyzer used',
            'batch_status' => 'Batch processing status',
            'lab_id' => 'Testing laboratory. Links to facility_details table'
        ]
    ],

    // Common query patterns and their optimal structures
    'query_patterns' => [
        'counts_by_category' => [
            'description' => 'Counting records by categorical breakdowns',
            'pattern' => 'SELECT category_column, COUNT(*) as count FROM table WHERE conditions GROUP BY category_column',
            'example' => 'SELECT vl_result_category, COUNT(*) as test_count FROM form_vl WHERE sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY vl_result_category'
        ],

        'temporal_trends' => [
            'description' => 'Analyzing trends over time',
            'pattern' => 'SELECT DATE_FORMAT(date_column, "%Y-%m") as month, COUNT(*) as count FROM table GROUP BY month ORDER BY month',
            'example' => 'SELECT DATE_FORMAT(sample_tested_datetime, "%Y-%m") as test_month, COUNT(*) as monthly_tests FROM form_vl GROUP BY test_month ORDER BY test_month'
        ],

        'facility_comparisons' => [
            'description' => 'Comparing metrics across facilities',
            'pattern' => 'SELECT f.facility_name, COUNT(t.*) as metric FROM table t JOIN facility_details f ON t.facility_id = f.facility_id GROUP BY f.facility_id',
            'example' => 'SELECT f.facility_name, COUNT(v.*) as vl_tests FROM form_vl v JOIN facility_details f ON v.facility_id = f.facility_id GROUP BY f.facility_id'
        ]
    ],

    'generic_patterns' => [
        // Lab breakdown patterns
        'break down by lab|group by lab|by testing lab|by laboratory' => [
            'pattern' => 'GROUP BY {table}.lab_id',
            'join_required' => 'JOIN facility_details fd ON {table}.lab_id = fd.facility_id',
            'select_addition' => 'fd.facility_name AS lab_name',
            'description' => 'Group results by testing laboratory',
            'applies_to_tables' => ['form_vl', 'form_eid', 'form_cd4', 'form_tb', 'form_hepatitis', 'form_covid19', 'form_generic_tests']
        ],

        // Facility breakdown patterns
        'break down by facility|group by clinic|by requesting facility|by collection site' => [
            'pattern' => 'GROUP BY {table}.facility_id',
            'join_required' => 'JOIN facility_details fd ON {table}.facility_id = fd.facility_id',
            'select_addition' => 'fd.facility_name AS facility_name',
            'description' => 'Group results by requesting facility/clinic',
            'applies_to_tables' => ['form_vl', 'form_eid', 'form_cd4', 'form_tb', 'form_hepatitis', 'form_covid19', 'form_generic_tests']
        ],

        // Temporal patterns
        'by month|monthly|over time|monthly breakdown|by time' => [
            'pattern' => 'GROUP BY DATE_FORMAT({table}.sample_tested_datetime, "%Y-%m")',
            'select_addition' => 'DATE_FORMAT({table}.sample_tested_datetime, "%Y-%m") AS test_month',
            'description' => 'Group results by month',
            'applies_to_tables' => ['form_vl', 'form_eid', 'form_cd4', 'form_tb', 'form_hepatitis', 'form_covid19', 'form_generic_tests']
        ],

        // Result breakdown patterns (test-specific)
        'by result|by outcome|breakdown by result' => [
            'pattern' => 'GROUP BY {table}.result',
            'description' => 'Group by test result/outcome',
            'applies_to_tables' => ['form_eid', 'form_cd4', 'form_tb', 'form_hepatitis', 'form_covid19', 'form_generic_tests']
        ],

        // VL-specific patterns
        'by suppression|suppressed vs not suppressed|by vl category' => [
            'pattern' => 'GROUP BY {table}.vl_result_category',
            'description' => 'Group VL tests by suppression status',
            'applies_to_tables' => ['form_vl']
        ],

        // Gender patterns
        'by gender|by sex|male vs female' => [
            'pattern' => 'GROUP BY {table}.patient_gender',
            'description' => 'Group by patient gender/sex',
            'applies_to_tables' => ['form_vl', 'form_eid', 'form_cd4', 'form_tb', 'form_hepatitis', 'form_covid19', 'form_generic_tests']
        ]
    ],

    // Validation rules for field combinations
    'field_validation' => [
        'required_joins' => [
            'by_lab' => '{t}.lab_id = facility_details.id',
            'lab_id' => '{t}.lab_id = facility_details.id',
            'facility_name' => 'Must JOIN facility_details table when selecting facility names',
            'user_names' => 'Must JOIN user_details table when selecting user information',
            'geographical_info' => 'Must JOIN geographical_divisions table for state/district names',
        ],

        'recommended_filters' => [
            'vl_queries' => [
                'exclude_rejected' => 'IFNULL(is_sample_rejected, "no") = "no"',
                'completed_only' => 'sample_tested_datetime IS NOT NULL',
                'valid_results' => 'result IS NOT NULL OR result_value_absolute IS NOT NULL'
            ],
            'temporal_queries' => [
                'recent_data' => 'sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)'
            ]
        ]
    ]
];
