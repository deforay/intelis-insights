# API Reference

Base URL: `http://localhost:8080`

All endpoints return JSON with `Content-Type: application/json`.

## Chat

### POST /api/v1/chat/ask

Main endpoint. Takes a natural language question, generates SQL via LLM, executes it, and returns results with chart suggestion.

**Request:**
```json
{
  "question": "What is the VL suppression rate by district?",
  "session_id": "optional-session-uuid"
}
```

**Response (200):**
```json
{
  "sql": "SELECT fd.facility_state AS district, ...",
  "verification": {
    "matches_intent": true,
    "confidence": 0.92,
    "reasoning": "Calculates VL suppression rate by district..."
  },
  "citations": ["table:form_vl", "col:form_vl.result_value_absolute"],
  "data": {
    "columns": ["district", "total_tests", "suppression_rate"],
    "rows": [{"district": "Centre", "total_tests": 5000, "suppression_rate": 78.5}],
    "count": 10
  },
  "chart": {
    "recommended": "bar",
    "alternatives": ["horizontal_bar", "table"],
    "config": {"x_axis": "district", "y_axis": "suppression_rate", "series": null, "title": ""},
    "reasoning": "Single categorical dimension with many categories"
  },
  "meta": {
    "execution_time_ms": 3200,
    "detected_intent": "aggregate",
    "sql_execution_time_ms": 45,
    "session_id": "abc-123"
  },
  "debug": {
    "tables_used": ["form_vl", "facility_details"],
    "conversation_context": []
  }
}
```

**Error (500):**
```json
{
  "error": "pipeline_error",
  "message": "Unable to generate SQL: insufficient context"
}
```

### POST /api/v1/chat/clear-context

Clears the in-memory conversation history for the current session.

**Response:**
```json
{"context_reset": true}
```

### GET /api/v1/chat/history

Returns the full conversation history for the current session.

**Response:**
```json
{
  "turns": [
    {
      "original_query": "How many VL tests last month?",
      "generated_sql": "SELECT COUNT(*) ...",
      "intent": "count",
      "tables_used": ["form_vl"],
      "filters_applied": {"time_period": "1 MONTH"},
      "row_count": 1,
      "result_summary": "Found 45,000 records",
      "timestamp": 1708905600
    }
  ],
  "count": 1
}
```

### GET /api/v1/chat/history/{index}

Returns a specific conversation turn by zero-based index.

**Response:** Single turn object (same shape as items in the `turns` array above).

**Error (404):**
```json
{"error": "History item not found"}
```

### POST /api/v1/chat/rewind/{index}

Truncates conversation history to the given index. The next question will use that turn as the most recent context.

**Response:**
```json
{
  "rewound_to": 1,
  "turns": [...],
  "count": 2
}
```

## Charts

### POST /api/v1/chart/suggest

Suggest chart type for given data. Useful if you want to re-suggest charts for existing data.

**Request:**
```json
{
  "data": {
    "columns": ["district", "count"],
    "rows": [{"district": "Centre", "count": 500}]
  },
  "intent": "aggregate",
  "query": "VL tests by district"
}
```

**Response:**
```json
{
  "recommended": "bar",
  "alternatives": ["horizontal_bar", "pie"],
  "config": {"x_axis": "district", "y_axis": "count", "series": null, "title": ""},
  "reasoning": "Single categorical dimension..."
}
```

## Reports

### GET /api/v1/reports

List all saved reports.

**Response:**
```json
[
  {
    "id": "uuid-123",
    "title": "Monthly VL Summary",
    "plan_json": {...},
    "chart_json": {...},
    "pinned": false,
    "created_at": "2026-02-26T10:00:00Z",
    "updated_at": "2026-02-26T10:00:00Z"
  }
]
```

### POST /api/v1/reports

Create a new report.

**Request:**
```json
{
  "title": "Monthly VL Summary",
  "plan_json": {},
  "chart_json": {}
}
```

**Response (201):**
```json
{"id": "uuid-123", "title": "Monthly VL Summary", ...}
```

### GET /api/v1/reports/{id}

Get a single report by ID.

### PUT /api/v1/reports/{id}

Update a report.

**Request:**
```json
{
  "title": "Updated Title",
  "plan_json": {},
  "chart_json": {},
  "pinned": true
}
```

### DELETE /api/v1/reports/{id}

Delete a report.

**Response (200):**
```json
{"deleted": true}
```

## Health

### GET /health

```json
{
  "status": "ok",
  "service": "Intelis Insights API",
  "version": "2.0.0"
}
```

### GET /status

Checks database connectivity.

```json
{
  "status": "ok",
  "database": "connected",
  "timestamp": "2026-02-26T10:00:00+00:00"
}
```
