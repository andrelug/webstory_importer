/**
 * Web Story Importer Admin JavaScript
 * Version: 1.0.19
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        
        // File input styling and preview
        $('.wsi-file-input').on('change', function() {
            const fileName = $(this).val().split('\\').pop();
            if (fileName) {
                $(this).after('<span class="wsi-selected-file">' + fileName + '</span>');
            }
        });

        // Error message tooltips
        $('.wsi-error-info').on('mouseover', function() {
            const errorMessage = $(this).attr('title');
            if (errorMessage) {
                const $tooltip = $('<div class="wsi-error-tooltip">' + errorMessage + '</div>');
                $('body').append($tooltip);
                
                const offset = $(this).offset();
                $tooltip.css({
                    top: offset.top - $tooltip.outerHeight() - 10,
                    left: offset.left - ($tooltip.outerWidth() / 2) + 10
                });
            }
        }).on('mouseout', function() {
            $('.wsi-error-tooltip').remove();
        });

        // Initialize status filtering
        $('.wsi-status-filter').on('change', function() {
            const selectedStatus = $(this).val();
            
            if (selectedStatus === 'all') {
                $('.wsi-stories-table tbody tr').show();
            } else {
                $('.wsi-stories-table tbody tr').hide();
                $('.wsi-stories-table tbody tr').each(function() {
                    const rowStatus = $(this).find('.wsi-status').attr('class').split('-').pop();
                    if (rowStatus === selectedStatus) {
                        $(this).show();
                    }
                });
            }
        });

        // Handle URL import form validation
        $('#wsi-url-import-form').on('submit', function(e) {
            const urlInput = $('#wsi_story_url').val();
            if (!urlInput || !isValidUrl(urlInput)) {
                e.preventDefault();
                alert('Please enter a valid URL for the Web Story');
                return false;
            }
            return true;
        });

        // Utility function to validate URLs
        function isValidUrl(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        }
    });

})(jQuery);
