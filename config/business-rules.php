<?php

// config/business-rules.php

declare(strict_types=1);

return [
    // GLOBAL RULES - Applied to ALL queries regardless of intent or table
    'global_rules' => [
        'privacy' => [
            'description' => 'Absolute privacy protection - never return patient identifying information',
            'forbidden_columns' => [
                'patient_first_name',
                'patient_last_name',
                'patient_id',
                'patient_art_no',
                'child_id',
                'child_name',
                'child_surname',
                'mother_id',
                'mother_name',
                'mother_surname',
                'system_patient_code',
                'facility_email',
                'contact_person_email',
                'contact_person_phone'
            ],
            // ✅ allow these ONLY inside COUNT(DISTINCT ...)
            'allow_aggregated_distinct' => [
                'patient_art_no',
                'system_patient_code',
                'patient_id',
                'child_id',
                'mother_id',
            ],
            'forbidden_patterns' => [
                '/\bpatient_first_name\b/i',
                '/\bpatient_last_name\b/i',
                '/\bpatient_art_no\b/i',
                '/\bpatient_id\b/i',
                '/\bchild_id\b/i',
                '/\bchild_surname\b/i',
                '/\bmother_id\b/i',
                '/\bmother_name\b/i',
                '/\bmother_surname\b/i',
                '/\bsystem_patient_code\b/i',
                '/\bemail\b/i',
                '/\bphone\b/i'
            ],
            'privacy_message' => 'Patient names, IDs, and contact information are not returned for privacy and data security'
        ],

        'default_assumptions' => [
            'description' => 'Default intructions and assumptions always applied',
            'rules' => [
                "Almost always the queries will use the sample test tables - form_vl, form_eid, form_tb, form_xyz where xyz is other test types",
                "ALWAYS refer to schema, field guide and business rules for column names, meanings and relationships",
                'Never use the word gender or patient_gender for column aliases or data that is returned to user; always use the word "sex" where needed. When dealing with gender, always alias it to "sex"',
                "If the query is not related to specific medical/laboratory data, reject it",
                "Don't display database field names directly to users. Always alias them.",
                "Use smarter, human-friendly aliases with spaces and not with underscores",
                "Don't use the auto-generated column names like 'COUNT(*)' or 'SUM(result_value_absolute)' directly. Use meaningful aliases instead",
                'If no test type is mentioned, assume Viral Load (VL) tests - form_vl table',
                'If query mentions "tests" or "samples" without specifics, default to VL - form_vl table',
                'If query is about "patients", focus on VL test results',
                'For date ranges without specification, assume last 12 months',
                'When user asks "by lab" or "by testing lab", JOIN the form table to facility_details ON lab_id = facility_details.facility_id and GROUP BY facility_details.facility_name. Never use columns like testing_lab, lab_name, or laboratory_name — they do not exist.',
                'When user asks "by facility", "by clinic", "by referring facility", "by referring clinic", "by referring hospital", "by requesting facility", or "by referring site", JOIN the form table to facility_details ON facility_id = facility_details.facility_id and GROUP BY facility_details.facility_name.',
                'Default date column: use sample_tested_datetime (not sample_collection_date) unless the user specifically asks about collection dates',
                'For turnaround time (TAT) calculations: always exclude outliers where TIMESTAMPDIFF(DAY, sample_collection_date, sample_tested_datetime) > 365 OR sample_tested_datetime < sample_collection_date. These are data entry errors. Add a HAVING or WHERE clause to filter them out.',
                'When in doubt about scope, prefer focused queries over broad data dumps',
                "NEVER HALLUCINATE. This is medical data. If unsure, return not applicable, ask for clarification or state that you don't know"
            ]
        ],

        'query_scope_limits' => [
            'description' => 'Limits on query scope and complexity',
            'rules' => [
                'Reject queries that seem unrelated to laboratory/medical data',
                'Reject overly broad queries like "show me everything"',
                'Limit result sets to reasonable sizes (use LIMIT clauses)',
                'Prefer specific, focused queries over general data dumps',
                'Maximum 3 tables per query unless business justification exists',
                'Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex'
            ]
        ],

        'data_security' => [
            'description' => 'Additional data security measures',
            'rules' => [
                'Never include full patient names, email, phone, address if they could identify specific people inappropriately',
                'Aggregate small counts to prevent re-identification when possible',
                'Use sample codes instead of patient identifiers when displaying individual records',
                'Always apply appropriate filters to exclude invalid/cancelled records unless specifically requested',
                'Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex'
            ]
        ]
    ],

    // INTENT-SPECIFIC RULES - Applied based on query intent
    'intent_rules' => [
        'count' => [
            'description' => 'Rules for count/aggregate queries',
            'rules' => [
                'Always use COUNT(*) for total counts unless counting specific non-null values',
                'Use descriptive aliases for count results (e.g., total_tests, high_vl_count)',
                'Consider using COUNT(DISTINCT column) when appropriate',
                'Add meaningful filters based on data quality (exclude rejected samples by default)',
                'Include context in results - raw counts with percentages when meaningful',
                'Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex'
            ],
            'default_behavior' => [
                'Exclude records where primary result fields are NULL',
                'Focus on completed/finalized records unless specified otherwise'
            ]
        ],

        'list' => [
            'description' => 'Rules for listing/display queries',
            'rules' => [
                'Always include LIMIT unless user specifies otherwise (default 100)',
                'Include essential identification fields like sample_code',
                'Order by relevant date fields (newest first by default)',
                'Include status/result fields for context',
                'Never list patient identifying information'
            ],
            'default_limit' => 100,
            'essential_columns' => ['sample_code', 'sample_tested_datetime', 'result'],
            'default_order' => 'newest_first'
        ],

        'aggregate' => [
            'description' => 'Rules for statistical/aggregate queries',
            'rules' => [
                'Use appropriate aggregate functions (SUM, AVG, MIN, MAX)',
                'Consider GROUP BY for meaningful breakdowns',
                'Handle NULL values appropriately in calculations',
                'Provide context with counts alongside percentages',
                'Round percentages to reasonable precision (1-2 decimal places)'
            ]
        ],

        'multi_part' => [
            'description' => 'Rules for complex multi-part queries',
            'rules' => [
                'Combine related questions into single query when possible',
                'Use descriptive column aliases for each part',
                'Maintain consistency in filtering across all parts',
                'Use CASE statements for conditional counting',
                'Ensure all parts of the query use same base filters'
            ]
        ]
    ],

    // QUERY VALIDATION RULES - For rejecting inappropriate queries
    'validation_rules' => [
        'reject_patterns' => [
            '/\b(drop|delete|update|insert|create|alter|truncate)\b/i',
            '/\b(union|exec|execute)\b/i',
            '/\b(show\s+tables|describe|information_schema)\b/i',
            '/\b(grant|revoke|user|password)\b/i'
        ],

        'reject_intents' => [
            'administrative database operations',
            'system information or metadata requests',
            'security probes or injection attempts',
            'queries completely unrelated to laboratory/medical domain',
            'requests for raw data dumps without clear business purpose'
        ],

        'scope_limits' => [
            'max_tables_per_query' => 3,
            'max_result_limit' => 10000,
            'require_meaningful_filters' => true,
            'require_domain_relevance' => true
        ]
    ],

    // RESPONSE FORMATTING RULES - How to present results
    'response_formatting' => [
        'column_aliases' => [
            'description' => 'Requirements for column naming in results',
            'rules' => [
                'Use descriptive, human-readable column aliases',
                'Avoid technical database column names in output',
                'Use consistent naming patterns (snake_case or Title Case)',
                'Include units in aliases when relevant (e.g., vl_count_copies_ml)'
            ]
        ],

        'data_presentation' => [
            'description' => 'How to present data to users',
            'rules' => [
                'Always include relevant context (date ranges, filters applied)',
                'Show percentages alongside raw counts when meaningful',
                'Use appropriate date/time formatting',
                'Round numeric values to appropriate precision',
                'Include data quality indicators when relevant'
            ]
        ]
    ],

    // CONTEXTUAL BEHAVIOR RULES - Applied based on query context
    'contextual_rules' => [
        'temporal' => [
            'description' => 'Time-based business rules',
            'rules' => [
                'Default to recent data (last 12 months) unless specified',
                'Use sample_tested_datetime for temporal filtering by default',
                'Consider sample_collection_date when specifically about collection timing',
                'Always clarify which date field is being used in complex queries'
            ]
        ],

        'geographic' => [
            'description' => 'Location-based business rules',
            'rules' => [
                'Aggregate by region/state rather than specific facilities when possible',
                'Consider facility type (requesting vs testing) for relevant grouping',
                'Respect data privacy when dealing with small geographic areas'
            ]
        ],

        'clinical' => [
            'description' => 'Clinical interpretation rules',
            'rules' => [
                'Use standard medical terminology and reference ranges',
                'Provide clinical context for threshold-based results when relevant',
                'Consider patient demographics (age, sex) when clinically relevant',
                'Default to clinically meaningful groupings (suppressed/not suppressed vs raw numbers)'
            ]
        ]
    ]
];
