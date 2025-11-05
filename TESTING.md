# Custody Logging Chain Testing Guide

This document describes how to test the custody logging chain (warehouse shelf → driver truck → technician truck stock).

## Prerequisites

1. WordPress installation with the Pick Sheet Unified plugin activated
2. At least one pick sheet created
3. A picker user account (role: `picker`)
4. A driver user account (role: `driver`)
5. A technician post created (post type: `technicians`)

## Database Schema

The custody events are stored in `{$wpdb->prefix}guinco_custody_events` table. The schema is automatically created on plugin activation.

## Testing the Full Chain

### Step 1: Create a Pick Sheet

1. Go to **Tools → Pick Sheet Import**
2. Upload a CSV file with the following columns:
   - `Technician` (name matching an existing technician post)
   - `PartNumber`
   - `Qty` or `Quantity`
   - `BinLoc`
   - Other columns as needed

### Step 2: Picker Workflow (PICKED Events)

1. Log in as a picker user
2. Navigate to the pick sheet post
3. Use the picker interface to scan parts:
   - Scan part number
   - Scan bin location
   - Scan shelf location
4. Each time a shelf is scanned, a **PICKED** event is logged:
   - Action: `PICKED`
   - From: `WAREHOUSE_SHELF`
   - To: `DRIVER_TRUCK`
   - Actor: `PICKER`

**Verify:**
- Check `wp-content/uploads/pick_sheet_logs/custody_events_YYYY_MM.csv` for new rows
- Check database table `wp_guinco_custody_events` for PICKED events
- Check `debug.log` for `[GUINCO] picker_scan_in` and `[GUINCO] picker_scan_logged` entries

### Step 3: Driver Workflow (LOADED Events)

1. Log in as a driver user
2. Navigate to the pick sheet with `?driver=1` query parameter
3. Mark parts as "Loaded" (checkbox or scan)
4. Each time a part is newly marked as loaded, a **LOADED** event is logged:
   - Action: `LOADED`
   - From: `WAREHOUSE_SHELF`
   - To: `DRIVER_TRUCK`
   - Actor: `DRIVER`

**Verify:**
- Check CSV file for LOADED events
- Check database for LOADED events
- Check `debug.log` for `[GUINCO] driver_scan_in` and `[GUINCO] driver_scan_logged` entries

### Step 4: Driver Workflow (DROPPED_OFF Events)

1. Still logged in as driver
2. Mark parts as "Delivered" (checkbox or scan)
3. Each time a part is newly marked as delivered, a **DROPPED_OFF** event is logged:
   - Action: `DROPPED_OFF`
   - From: `DRIVER_TRUCK`
   - To: `TECH_TRUCK`
   - Actor: `DRIVER`

**Verify:**
- Check CSV file for DROPPED_OFF events
- Check database for DROPPED_OFF events

### Step 5: Complete Driver Sheet (Validation)

1. Click "Complete Driver Sheet" button
2. The system validates that:
   - For each part, there are sufficient LOADED events (sum of qty >= required qty)
   - For each part, there are sufficient DROPPED_OFF events (sum of qty >= required qty)
3. If validation passes:
   - A **STOCKED** system event is logged
   - Driver sheet is marked complete
   - CSV log file is generated
4. If validation fails:
   - Error message: "Missing custody events; cannot complete."
   - Completion is blocked

**Verify:**
- Try completing with missing events → should fail
- Complete all required scans → should succeed
- Check for STOCKED event in database

## Manual Testing via Database

### Query Events for a Pick Sheet

```sql
SELECT * FROM wp_guinco_custody_events 
WHERE pick_sheet_id = 123 
ORDER BY ts_utc ASC;
```

### Check Chain Completeness

```sql
SELECT 
    part_number,
    action,
    SUM(qty) as total_qty
FROM wp_guinco_custody_events
WHERE pick_sheet_id = 123
GROUP BY part_number, action
ORDER BY part_number, action;
```

### Verify CSV Logging

Check file: `wp-content/uploads/pick_sheet_logs/custody_events_YYYY_MM.csv`

The file should:
- Have a header row on first write
- Append new events immediately after DB insert
- Use NULL for empty JSON fields

## Test Scenarios

### Scenario 1: Complete Chain

1. Picker scans 3 parts (qty=1 each)
2. Driver loads all 3 parts
3. Driver delivers all 3 parts
4. Driver completes sheet → **SUCCESS**

### Scenario 2: Incomplete Chain

1. Picker scans 3 parts
2. Driver loads only 2 parts
3. Driver tries to complete → **FAIL** (missing LOADED events)

### Scenario 3: Missing Delivery

1. Picker scans 3 parts
2. Driver loads all 3 parts
3. Driver delivers only 2 parts
4. Driver tries to complete → **FAIL** (missing DROPPED_OFF events)

## Debug Logging

Enable WordPress debug logging in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Look for entries prefixed with `[GUINCO]` in `wp-content/debug.log`:

- `[GUINCO] picker_scan_in: ...`
- `[GUINCO] picker_scan_logged: event_uuid=...`
- `[GUINCO] driver_scan_in: ...`
- `[GUINCO] driver_scan_logged: event_uuid=...`

## Example CSV Line

```
event_uuid,pick_sheet_id,tech_id,part_number,qty,action,from_loc,to_loc,actor_role,actor_user_id,device_id,ts_utc,source,extra_json
550e8400-e29b-41d4-a716-446655440000,123,45,PART-12345,1,PICKED,WAREHOUSE_SHELF,DRIVER_TRUCK,PICKER,10,Mozilla/5.0...,2024-01-15 10:30:00,picker,"{""bin"":""A1"",""shelf"":""S2"",""pick_time"":1705314600}"
```

## Troubleshooting

### Table Not Created

- Run: `guinco_install_schema()` via WP-CLI or admin
- Or deactivate/reactivate plugin

### Events Not Logging

- Check `tech_id` is resolved correctly (technician post must exist)
- Check `debug.log` for error messages
- Verify user is logged in (actor_user_id must be valid)

### CSV Not Appending

- Check `wp-content/uploads/pick_sheet_logs/` directory permissions
- Verify disk space
- Check `debug.log` for file write errors

### Validation Failing

- Query database to verify event counts match expected quantities
- Check that parts are not marked as "missing" (missing parts are excluded from validation)
- Verify all prior steps (PICKED → LOADED → DROPPED_OFF) completed successfully

