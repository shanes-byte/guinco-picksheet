(function ($) {
    'use strict';
    var nonce        = psdData.nonce;
    var postId       = psdData.post_id;
    var driverMode   = psdData.driver_mode || 'simple';
    var driverLoaded = Array.isArray(psdData.driver_loaded) ? psdData.driver_loaded.slice() : [];
    var driverMissing= Array.isArray(psdData.driver_missing) ? psdData.driver_missing.slice() : [];
    var completed    = psdData.completed;
    var currentPart  = null;
    var currentStep  = driverMode === 'strict' ? 'part' : 'bin';
    $(function () {
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
        if (driverMode === 'strict') {
            if (currentStep === 'part') {
                // Find row by part
                var $row = $('.psd-table tbody tr[data-part="' + code + '"]');
                if ($row.length) {
                    // mark loaded
                    var part = code;
                    currentPart = part;
                    if (driverLoaded.indexOf(part) === -1) {
                        driverLoaded.push(part);
                        $row.find('.psd-load').prop('checked', true).trigger('change');
                    }
                    currentStep = 'bin';
                } else {
                    alert('Part not found: ' + code);
                }
            } else if (currentStep === 'bin') {
                // We don't store bin separately for driver; move to truck.
                currentStep = 'truck';
            } else if (currentStep === 'truck') {
                // Mark delivered for currentPart or for all loaded parts
                if (currentPart) {
                    markDelivered(currentPart);
                    currentPart = null;
                }
                currentStep = 'part';
            }
        } else {
            // simple: bin then truck
            if (currentStep === 'bin') {
                currentStep = 'truck';
            } else if (currentStep === 'truck') {
                // mark all loaded parts delivered
                driverLoaded.forEach(function (part) {
                    markDelivered(part);
                });
                currentStep = 'bin';
            }
        }
    }
    function markDelivered(part) {
        var $row = $('.psd-table tbody tr[data-part="' + part + '"]');
        if ($row.length) {
            $row.find('.psd-deliver').prop('checked', true);
            // Save delivered state on server via meta; we mark using meta key driver_delivered_{part}
            $.post(psdData.ajax_url, {
                action: 'psd_mark_delivered',
                nonce: nonce,
                post_id: postId,
                part: part
            });
        }
    }
    function saveProgress(callback) {
        $.post(psdData.ajax_url, {
            action: 'psd_save_progress',
            nonce: nonce,
            post_id: postId,
            driver_loaded: driverLoaded,
            driver_missing: driverMissing
        }, function (res) {
            if (res.success) {
                if (typeof callback === 'function') {
                    callback();
                }
            } else {
                alert('Driver save failed');
            }
        });
    }
})(jQuery);