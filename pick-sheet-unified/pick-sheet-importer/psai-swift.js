/**
 * JS for the simplified picker UI (SwiftPick Sheet).
 *
 * This script handles scanning the next item, bin, and shelf in sequence. It
 * updates the picked details via AJAX and reloads the page when an item is
 * completed so that the next unpicked item is shown. The Save Progress and
 * Complete buttons call the original importer AJAX actions.
 */
(function ($) {
    'use strict';
    var nonce         = psaiSwiftData.nonce;
    var postId        = psaiSwiftData.post_id;
    // currentIndex is the row index of the current item to pick
    var currentIndex  = psaiSwiftData.current_index;
    // pickedItems stores row indexes of picked items
    var pickedItems   = Array.isArray(psaiSwiftData.picked_items) ? psaiSwiftData.picked_items.slice() : [];
    // pickedDetails keyed by row index, containing bin, shelf, time
    var pickedDetails = typeof psaiSwiftData.picked_details === 'object' ? JSON.parse(JSON.stringify(psaiSwiftData.picked_details)) : {};
    var order         = psaiSwiftData.order || 'asc';
    var currentStep   = 'part';
    // Update input placeholder based on current step
    function updatePlaceholder() {
        var $input = $('#psai-swift-search');
        if (currentStep === 'part') {
            $input.attr('placeholder', 'Scan part...');
        } else if (currentStep === 'bin') {
            $input.attr('placeholder', 'Scan bin...');
        } else if (currentStep === 'shelf') {
            $input.attr('placeholder', 'Scan shelf...');
        }
    }
    // currentPart is the expected part number for scanning
    var currentPart   = null;

    $(function () {
        // Determine current part and row index from the table row
        var $row    = $('.psai-swift-table tbody tr');
        currentPart = $row.data('part');
        // currentIndex already set from localized data
        // Scanner input handler
        var lastCode  = '';
        var lastStamp = 0;
        $('#psai-swift-search').on('keydown', function (e) {
            if (e.which === 13 || e.which === 10) {
                e.preventDefault();
                var code = $(this).val().trim();
                if (!code) return;
                var now = Date.now();
                if (code === lastCode && (now - lastStamp) < 300) {
                    $(this).val('');
                    return;
                }
                lastCode  = code;
                lastStamp = now;
                handleScan(code);
                $(this).val('');
            }
        });
        // Set initial placeholder
        updatePlaceholder();
        // Save progress button
        $('#psai-swift-save').on('click', function () {
            saveProgress(function () {
                $('#psai-swift-feedback').html('<div class="updated notice">Progress saved.</div>');
                setTimeout(function () { $('#psai-swift-feedback').html(''); }, 3000);
            });
        });
        // Complete sheet button
        $('#psai-swift-complete').on('click', function () {
            if (!confirm('All parts picked? Completing will lock further edits. Proceed?')) return;
            // Save first
            saveProgress(function () {
                $.post(psaiSwiftData.ajax_url, {
                    action: 'psai_complete_sheet',
                    nonce: nonce,
                    post_id: postId
                }, function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert('Error completing sheet: ' + res.data);
                    }
                });
            });
        });
    });
    function handleScan(code) {
        if (currentStep === 'part') {
            // Expect to scan the part number
            if (code !== currentPart) {
                alert('Expected part: ' + currentPart + ', scanned: ' + code);
                return;
            }
            // Initialize pickedDetails record for this row index
            pickedDetails[currentIndex] = pickedDetails[currentIndex] || {};
            currentStep = 'bin';
            updatePlaceholder();
        } else if (currentStep === 'bin') {
            // Save bin code
            pickedDetails[currentIndex] = pickedDetails[currentIndex] || {};
            pickedDetails[currentIndex].bin = code;
            currentStep = 'shelf';
            updatePlaceholder();
        } else if (currentStep === 'shelf') {
            // Save shelf code and time, then mark item complete
            pickedDetails[currentIndex] = pickedDetails[currentIndex] || {};
            pickedDetails[currentIndex].shelf = code;
            pickedDetails[currentIndex].time = Math.floor(Date.now() / 1000);
            // Add to pickedItems if not present
            if (pickedItems.indexOf(currentIndex) === -1) {
                pickedItems.push(currentIndex);
            }
            currentStep = 'part';
            updatePlaceholder();
            // Save progress and reload to next item
            saveProgress(function () {
                window.location.reload();
            });
        }
    }
    function saveProgress(callback) {
        $.post(psaiSwiftData.ajax_url, {
            action: 'psai_save_progress',
            nonce: nonce,
            post_id: postId,
            picked_items: pickedItems,
            picked_details: pickedDetails
        }, function (res) {
            if (res.success) {
                if (typeof callback === 'function') {
                    callback();
                }
            } else {
                alert('Save failed');
            }
        });
    }
})(jQuery);

