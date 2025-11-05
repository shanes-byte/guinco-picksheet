(function ($) {
    'use strict';
    var nonce        = psdData.nonce;
    var postId       = psdData.post_id;
    var driverMode   = psdData.driver_mode || 'simple';
    var driverLoaded = Array.isArray(psdData.driver_loaded) ? psdData.driver_loaded.slice() : [];
    var driverMissing= Array.isArray(psdData.driver_missing) ? psdData.driver_missing.slice() : [];
    var driverDelivered = Array.isArray(psdData.driver_delivered) ? psdData.driver_delivered.slice() : [];
    var driverDetails = psdData.driver_details || { loaded: {}, delivered: {} };
    var completed    = psdData.completed;
    var currentPart  = null;
    var currentStep  = driverMode === 'strict' ? 'part' : 'bin';

    function updateScanStatus() {
        var status = '';
        if (completed) {
            status = 'Sheet completed';
        } else if (driverMode === 'strict') {
            if (currentStep === 'part') {
                status = 'Scan part numbers to load, or use checkboxes. Scan shelf codes to assign truck locations.';
            } else if (currentStep === 'bin') {
                status = 'Scan shelf codes to assign truck locations to loaded parts.';
            } else if (currentStep === 'truck') {
                status = 'Scan truck shelf location for loaded parts.';
            }
        } else {
            if (currentStep === 'bin') {
                status = 'Step 1: Scan any bin to continue';
            } else if (currentStep === 'truck') {
                status = 'Step 2: Scan truck shelf location for all loaded parts';
            }
        }
        $('#psd-scan-status').text(status);
    }
    $(function () {
        // Initialize location display and status
        updateLocationDisplay();
        updateScanStatus();

        // If completed, disable controls
        if (completed) {
            $('#psd-search, #psd-save-progress, #psd-complete-sheet').prop('disabled', true);
            $('.psd-table input').prop('disabled', true);
            return;
        }
        $('#psd-search').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                var code = $(this).val().trim();
                if (!code) return;
                handleScan(code);
                $(this).val('');
            }
        });
        // Manual toggles for loaded/missing/delivered
        $('.psd-table').on('change', '.psd-load', function () {
            var part = $(this).val();
            if ($(this).is(':checked')) {
                if (driverLoaded.indexOf(part) === -1) driverLoaded.push(part);
            } else {
                driverLoaded = driverLoaded.filter(function (p) { return p !== part; });
            }
        });
        $('.psd-table').on('change', '.psd-missing', function () {
            var part = $(this).val();
            if ($(this).is(':checked')) {
                if (driverMissing.indexOf(part) === -1) driverMissing.push(part);
            } else {
                driverMissing = driverMissing.filter(function (p) { return p !== part; });
            }
        });
        $('.psd-table').on('change', '.psd-deliver', function () {
            var part = $(this).val();
            if ($(this).is(':checked')) {
                if (driverDelivered.indexOf(part) === -1) driverDelivered.push(part);
                // If part was loaded and has truck shelf, copy to delivered
                if (driverDetails.loaded[part] && driverDetails.loaded[part].truck_shelf) {
                    if (!driverDetails.delivered[part]) {
                        driverDetails.delivered[part] = {};
                    }
                    driverDetails.delivered[part].truck_shelf = driverDetails.loaded[part].truck_shelf;
                    driverDetails.delivered[part].time = Math.floor(Date.now() / 1000);
                    updateLocationDisplay();
                }
                updateDeliveredMeta(part, true);
            } else {
                driverDelivered = driverDelivered.filter(function (p) { return p !== part; });
                updateDeliveredMeta(part, false);
                updateLocationDisplay();
            }
        });
        // Save button
        $('#psd-save-progress').on('click', function () {
            saveProgress(function () {
                $('#psd-save-progress').after('<span class="psd-save-feedback" style="margin-left:10px;">Saved</span>');
                setTimeout(function () { $('.psd-save-feedback').fadeOut(300, function () { $(this).remove(); }); }, 3000);
            });
        });
        // Complete button
        $('#psd-complete-sheet').on('click', function () {
            if (!confirm('Are all required items loaded and delivered? Completing will lock the sheet.')) return;
            saveProgress(function () {
                $.post(psdData.ajax_url, {
                    action: 'psd_complete_sheet',
                    nonce: nonce,
                    post_id: postId
                }, function (res) {
                    if (res.success) {
                        $('#psd-success').html('<div class="updated notice">Driver sheet completed. <a href="' + res.data.file_url + '" target="_blank">Download log</a>.</div>');
                        // Disable controls
                        $('.psd-table input, #psd-search, #psd-save-progress, #psd-complete-sheet').prop('disabled', true);
                    } else {
                        alert('Error completing driver sheet: ' + res.data);
                    }
                });
            });
        });
    });
    function handleScan(code) {
        if (completed) return;

        // Check if it's a part number
        var $partRow = $('.psd-table tbody tr[data-part="' + code + '"]');
        if ($partRow.length) {
            // It's a part number - load it (works in any mode)
            var part = code;
            currentPart = part;
            if (driverLoaded.indexOf(part) === -1) {
                driverLoaded.push(part);
                $partRow.find('.psd-load').prop('checked', true).trigger('change');
            }
            // If in strict mode, advance to bin step
            if (driverMode === 'strict') {
                currentStep = 'bin';
                updateScanStatus();
            }
            return;
        }

        // Not a part number - treat as location/shelf code
        if (driverMode === 'strict') {
            if (currentStep === 'part' || currentStep === 'bin') {
                // If we have loaded parts, assume this is a truck shelf scan
                if (driverLoaded.length > 0) {
                    currentStep = 'truck';
                    // Fall through to truck handling
                } else {
                    alert('Please load some parts first using checkboxes or scanning part numbers.');
                    return;
                }
            }
            if (currentStep === 'truck') {
                // Store truck shelf location
                driverLoaded.forEach(function (part) {
                    if (!driverDetails.loaded[part]) {
                        driverDetails.loaded[part] = {};
                    }
                    driverDetails.loaded[part].truck_shelf = code;
                    driverDetails.loaded[part].time = Math.floor(Date.now() / 1000);

                    // If already delivered, also update delivered truck shelf
                    if (driverDelivered.indexOf(part) !== -1) {
                        if (!driverDetails.delivered[part]) {
                            driverDetails.delivered[part] = {};
                        }
                        driverDetails.delivered[part].truck_shelf = code;
                        driverDetails.delivered[part].time = Math.floor(Date.now() / 1000);
                    }

                    // Mark as delivered if not already
                    markDelivered(part);
                });
                updateLocationDisplay();
                currentStep = 'part';
                updateScanStatus();
            }
        } else {
            // simple mode
            if (currentStep === 'bin') {
                currentStep = 'truck';
                updateScanStatus();
            }
            if (currentStep === 'truck') {
                // Store truck shelf location for all loaded parts
                var truckShelf = code;
                driverLoaded.forEach(function (part) {
                    if (!driverDetails.loaded[part]) {
                        driverDetails.loaded[part] = {};
                    }
                    driverDetails.loaded[part].truck_shelf = truckShelf;
                    driverDetails.loaded[part].time = Math.floor(Date.now() / 1000);
                    // Also store for delivered if already delivered
                    if (driverDelivered.indexOf(part) !== -1) {
                        if (!driverDetails.delivered[part]) {
                            driverDetails.delivered[part] = {};
                        }
                        driverDetails.delivered[part].truck_shelf = truckShelf;
                        driverDetails.delivered[part].time = Math.floor(Date.now() / 1000);
                    }
                });
                // Mark all loaded parts as delivered
                driverLoaded.forEach(function (part) {
                    markDelivered(part);
                });
                updateLocationDisplay();
                currentStep = 'bin';
                updateScanStatus();
            }
        }
    }
    function markDelivered(part) {
        var $row = $('.psd-table tbody tr[data-part="' + part + '"]');
        if ($row.length) {
            $row.find('.psd-deliver').prop('checked', true);
            if (driverDelivered.indexOf(part) === -1) {
                driverDelivered.push(part);
            }
            // Store truck shelf for delivered if not already set
            if (!driverDetails.delivered[part] || !driverDetails.delivered[part].truck_shelf) {
                if (driverDetails.loaded[part] && driverDetails.loaded[part].truck_shelf) {
                    // Use same truck shelf from loaded
                    if (!driverDetails.delivered[part]) {
                        driverDetails.delivered[part] = {};
                    }
                    driverDetails.delivered[part].truck_shelf = driverDetails.loaded[part].truck_shelf;
                    driverDetails.delivered[part].time = Math.floor(Date.now() / 1000);
                }
            }
            updateDeliveredMeta(part, true);
        }
    }
    function updateDeliveredMeta(part, isDelivered) {
        $.post(psdData.ajax_url, {
            action: 'psd_mark_delivered',
            nonce: nonce,
            post_id: postId,
            part: part,
            delivered: isDelivered ? 'yes' : 'no'
        });
    }
    function saveProgress(callback) {
        $.post(psdData.ajax_url, {
            action: 'psd_save_progress',
            nonce: nonce,
            post_id: postId,
            driver_loaded: driverLoaded,
            driver_missing: driverMissing,
            driver_delivered: driverDelivered,
            driver_details: driverDetails
        }, function (res) {
            if (res.success) {
                // Update location display after save
                updateLocationDisplay();
                if (typeof callback === 'function') {
                    callback();
                }
            } else {
                alert('Driver save failed');
            }
        });
    }
    function updateLocationDisplay() {
        // Update location columns in table based on current driver_details
        $('.psd-table tbody tr').each(function() {
            var $row = $(this);
            var part = $row.data('part');
            var currentLocation = '';

            // Determine current location priority: driver's truck shelf (regardless of checkbox state) > original data
            if (driverDetails.delivered && driverDetails.delivered[part] && driverDetails.delivered[part].truck_shelf) {
                currentLocation = driverDetails.delivered[part].truck_shelf;
            } else if (driverDetails.loaded && driverDetails.loaded[part] && driverDetails.loaded[part].truck_shelf) {
                currentLocation = driverDetails.loaded[part].truck_shelf;
            }

            // Update location columns if we found a truck shelf location
            if (currentLocation) {
                $row.find('td').each(function() {
                    var $td = $(this);
                    var $th = $row.closest('table').find('thead th').eq($td.index());
                    var colName = $th.data('column');
                    if ($th.data('location-column') || ['BinLoc', 'Shelf', 'ShelfLoc', 'ShelfNumber', 'Location'].indexOf(colName) !== -1) {
                        $td.text(currentLocation);
                    }
                });
                $row.attr('data-current-location', currentLocation);
            }
        });
    }
})(jQuery);