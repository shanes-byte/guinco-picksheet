(function($){
  'use strict';
  $(document).ready(function(){
    // Only proceed if search input exists
    var $search = $('#psai-search');
    if ($search.length) {
      // Assign data-part attributes and checkbox values based on partnumber cell if not already present
      $('.psai-table tbody tr').each(function(){
        var $row = $(this);
        // If data-part is empty, derive from the 6th cell (index 5)
        var part = $row.attr('data-part');
        var cellText = $row.find('td').eq(5).text().trim();
        if (!part) {
          part = cellText;
          $row.attr('data-part', part);
        }
        // Also set checkbox value to part number
        $row.find('.psai-pick-checkbox').val(part);
      });

      // Remove existing keypress/keydown handlers to avoid duplicate alerts
      $search.off('keypress.psaiScanNamespace keydown.psaiScanNamespace');

      var lastCode = '';
      var lastTime = 0;
      $search.on('keydown.psaiScanNamespace', function(e){
        // Handle Enter key (keyCode 13) and scanner carriage return (10)
        if (e.which === 13 || e.which === 10) {
          e.preventDefault();
          e.stopImmediatePropagation();
          var code = $(this).val().trim();
          if (!code) return;
          var now = Date.now();
          // Suppress duplicates within 300ms
          if (code === lastCode && (now - lastTime) < 300) {
            $(this).val('');
            return;
          }
          lastCode = code;
          lastTime = now;
          $(this).val('');

          // Find row by data-part first
          var $row = $('.psai-table tbody tr[data-part="' + code + '"]');
          if (!$row.length) {
            // Fallback: search by cell text (case-insensitive)
            $row = $('.psai-table tbody tr').filter(function(){
              return $(this).find('td').eq(5).text().trim().toLowerCase() === code.toLowerCase();
            });
          }
          if ($row.length) {
            // Scroll into view and highlight
            $('html, body').animate({ scrollTop: $row.offset().top - 200 }, 300);
            $row.addClass('psai-flash');
            setTimeout(function(){ $row.removeClass('psai-flash'); }, 800);
            // Check the checkbox and trigger change to update pickedItems
            var $checkbox = $row.find('.psai-pick-checkbox');
            $checkbox.prop('checked', true).trigger('change');
            // Set currentPartKey and currentStep if variables exist (defined in original script)
            if (typeof currentPartKey !== 'undefined' && typeof currentStep !== 'undefined') {
              currentPartKey = code;
              currentStep = 'bin';
              if (typeof pickedDetails === 'object' && !pickedDetails[code]) {
                pickedDetails[code] = {};
              }
            }
          } else {
            alert('Part not found: ' + code);
          }
        }
      });
    }

    // Driver plugin search fix (#psd-search)
    var $dsearch = $('#psd-search');
    if ($dsearch.length) {
      // Only modify once
      $dsearch.off('keypress.psdScanNamespace keydown.psdScanNamespace');
      var lastDCode = '';
      var lastDTime = 0;
      $dsearch.on('keydown.psdScanNamespace', function(e){
        if (e.which === 13 || e.which === 10) {
          e.preventDefault();
          e.stopImmediatePropagation();
          var code = $(this).val().trim();
          if (!code) return;
          var now = Date.now();
          if (code === lastDCode && (now - lastDTime) < 300) {
            $(this).val('');
            return;
          }
          lastDCode = code;
          lastDTime = now;
          $(this).val('');
          // Call existing handleScan if available
          if (typeof handleScan === 'function') {
            handleScan(code);
          }
        }
      });
    }
  });
})(jQuery);
