(function ($) {
    'use strict';

    // Utility: get CSRF nonce.
    var psaiNonce = psaiData.nonce;

    // Determine state from localized data.
    // Use row index keys rather than part numbers
    var pickedItems   = Array.isArray(psaiData.picked_items) ? psaiData.picked_items.slice() : [];
    var pickedDetails = typeof psaiData.picked_details === 'object' ? JSON.parse(JSON.stringify(psaiData.picked_details)) : {};
    var currentIndex  = null;
    var currentStep   = 'part'; // part -> bin -> shelf

    pickedItems = pickedItems.map(function (item) {
        var idx = parseInt(item, 10);
        return isNaN(idx) ? item : idx;
    });

    // Update input placeholder based on current step
    function updatePlaceholder() {
        var $input = $('#psai-search');
        if (currentStep === 'part') {
            $input.attr('placeholder', 'Scan part...');
        } else if (currentStep === 'bin') {
            $input.attr('placeholder', 'Scan bin...');
        } else if (currentStep === 'shelf') {
            $input.attr('placeholder', 'Scan shelf...');
        }
    }

    // On document ready.
    $(function () {
        // Search/scanner input handling.
        $('#psai-search').on('keydown', function (e) {
            if (e.which === 13 || e.which === 10) {
                e.preventDefault();
                var code = $(this).val().trim();
                if (!code) return;
                handleScan(code);
                $(this).val('');
            }
        });

        // Set initial placeholder
        updatePlaceholder();

        // Checkbox toggle changes pickedItems array.
        $('.psai-table').on('change', '.psai-pick-checkbox', function () {
            var $row   = $(this).closest('tr');
            var index  = parseInt($(this).val(), 10);
            if (isNaN(index)) {
                return;
            }
            if ($(this).is(':checked')) {
                if (pickedItems.indexOf(index) === -1) {
                    pickedItems.push(index);
                }
                if (!pickedDetails[index]) {
                    pickedDetails[index] = {};
                }
                if (!pickedDetails[index].bin) {
                    pickedDetails[index].bin = $row.data('bin') || '';
                }
                if (!pickedDetails[index].shelf) {
                    pickedDetails[index].shelf = $row.data('shelf') || '';
                }
                pickedDetails[index].time = pickedDetails[index].time || Math.floor(Date.now() / 1000);
                pickedDetails[index].manual = true;
            } else {
                var idx = pickedItems.indexOf(index);
                if (idx !== -1) {
                    pickedItems.splice(idx, 1);
                }
                if (pickedDetails.hasOwnProperty(index)) {
                    delete pickedDetails[index];
                }
            }
        });

        // Section order preference change.
        $('input[name="psai-section-order"]').on('change', function () {
            var pref = $(this).val();
            $.post(psaiData.ajax_url, {
                action: 'psai_save_order_pref',
                nonce: psaiNonce,
                order: pref
            }, function () {
                location.reload();
            });
        });

        // Save progress button.
        $('#psai-save-progress').on('click', function () {
            saveProgress(function (msg) {
                var $btn = $('#psai-save-progress');
                $btn.after('<span class="psai-save-feedback" style="margin-left:10px;">' + msg + '</span>');
                setTimeout(function () {
                    $('.psai-save-feedback').fadeOut(300, function () { $(this).remove(); });
                }, 3000);
            });
        });

        // Complete sheet button.
        $('#psai-complete-sheet').on('click', function () {
            if (!confirm('All parts picked? Completing the sheet will lock further edits. Proceed?')) return;
            saveProgress(function () {
                // After saving, request completion.
                $.post(psaiData.ajax_url, {
                    action: 'psai_complete_sheet',
                    nonce: psaiNonce,
                    post_id: psaiData.post_id
                }, function (res) {
                    if (res.success) {
                        $('#psai-success').html('<div class="updated notice">Pick sheet complete. <a href="' + res.data.file_url + '" target="_blank">Download log</a>.</div>');
                        // Disable inputs
                        $('input.psai-pick-checkbox, #psai-search, #psai-save-progress, #psai-complete-sheet').prop('disabled', true);
                    } else {
                        alert('Error completing sheet: ' + res.data);
                    }
                });
            });
        });
    });

    /**
     * Handle scanned code depending on current step.
     */
    function handleScan(code) {
        if (psaiData.completed) return;
        // Determine step
        if (currentStep === 'part') {
            // find first unpicked row with matching part number
            var foundRow = null;
            var foundIndex = null;
            $('.psai-table tbody tr').each(function () {
                var idx = parseInt($(this).data('index'), 10);
                var part = $(this).data('part');
                if (String(part).trim() === String(code).trim() && pickedItems.indexOf(idx) === -1) {
                    foundRow = $(this);
                    foundIndex = idx;
                    return false;
                }
            });
            if (foundRow) {
                // scroll into view and highlight
                $('html, body').animate({ scrollTop: foundRow.offset().top - 200 }, 300);
                foundRow.addClass('psai-flash');
                setTimeout(function () { foundRow.removeClass('psai-flash'); }, 800);
                currentIndex = foundIndex;
                currentStep  = 'bin';
                // Check the checkbox
                var $checkbox = foundRow.find('.psai-pick-checkbox');
                $checkbox.prop('checked', true).trigger('change');
                // ensure pickedDetails record exists
                if (!pickedDetails[currentIndex]) {
                    pickedDetails[currentIndex] = {};
                }
                updatePlaceholder();
            } else {
                alert('Part not found or already picked: ' + code);
            }
        } else if (currentStep === 'bin') {
            if (currentIndex !== null) {
                pickedDetails[currentIndex] = pickedDetails[currentIndex] || {};
                pickedDetails[currentIndex].bin = code;
                currentStep = 'shelf';
                updatePlaceholder();
            }
        } else if (currentStep === 'shelf') {
            if (currentIndex !== null) {
                pickedDetails[currentIndex] = pickedDetails[currentIndex] || {};
                pickedDetails[currentIndex].shelf = code;
                pickedDetails[currentIndex].time  = Math.floor(Date.now() / 1000);
                // Mark row index as picked if not already
                if (pickedItems.indexOf(currentIndex) === -1) {
                    pickedItems.push(currentIndex);
                }
                currentIndex = null;
                currentStep  = 'part';
                updatePlaceholder();
            }
        }
    }

    /**
     * Save progress via AJAX.
     * @param {Function} callback
     */
    function saveProgress(callback) {
        $.post(psaiData.ajax_url, {
            action: 'psai_save_progress',
            nonce: psaiNonce,
            post_id: psaiData.post_id,
            picked_items: pickedItems,
            picked_details: pickedDetails
        }, function (res) {
            if (res.success) {
                if (typeof callback === 'function') {
                    callback(res.data.message || 'Saved');
                }
            } else {
                alert('Save failed');
            }
        });
    }
})(jQuery);