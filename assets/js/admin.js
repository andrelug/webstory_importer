/**
 * Web Story Importer Admin Scripts
 * Version: 1.0.26
 */
(function($) {
    'use strict';

    // Initialize on document ready
    $(document).ready(function() {
        initTabNavigation();
        initFileUpload();
        initImportForm();
        checkForImportErrors();
    });

    /**
     * Initialize tab navigation
     */
    function initTabNavigation() {
        $('.nav-tab').on('click', function(e) {
            // The default navigation is handled by WordPress
        });
    }

    /**
     * Check for import errors on page load and display them appropriately
     */
    function checkForImportErrors() {
        // If there are WordPress admin notices, also show them in our UI
        const $adminNotices = $('.wrap > .notice');
        
        if ($adminNotices.length) {
            $adminNotices.each(function() {
                const isError = $(this).hasClass('notice-error');
                const isSuccess = $(this).hasClass('notice-success');
                const message = $(this).find('p').text();
                
                if (message && (isError || isSuccess)) {
                    const statusClass = isError ? 'error' : 'success';
                    const $importForms = $('.wsi-import-form');
                    
                    $importForms.each(function() {
                        const $statusArea = $('<div class="wsi-status-message wsi-' + statusClass + '"></div>');
                        $statusArea.text(message);
                        $(this).prepend($statusArea);
                    });
                }
            });
        }
    }

    /**
     * Initialize file upload preview
     */
    function initFileUpload() {
        $('.wsi-file-input').on('change', function() {
            var fileName = '';
            
            if (this.files && this.files.length > 0) {
                fileName = this.files[0].name;
                
                // Show file name in preview area
                $(this).closest('.wsi-file-input-container').find('.wsi-file-name').text(fileName);
                
                // Add a class to indicate file selected
                $(this).closest('.wsi-file-input-container').addClass('has-file');
            } else {
                // Reset to default text if no file selected
                $(this).closest('.wsi-file-input-container').find('.wsi-file-name').text(wsiData.noFileSelected || 'No file selected');
                
                // Remove the class indicating file selected
                $(this).closest('.wsi-file-input-container').removeClass('has-file');
            }
        });
    }

    /**
     * Initialize import form submission with visual feedback
     */
    function initImportForm() {
        $('.wsi-import-form').on('submit', function(e) {
            var $form = $(this);
            var $fileInput = $form.find('input[type="file"]');
            var $urlInput = $form.find('input[type="url"]');
            var isValid = true;
            
            // Remove any existing status messages
            $form.find('.wsi-status-message').remove();
            
            // Check if this is a file upload form and validate file input
            if ($fileInput.length && $fileInput[0].files.length === 0) {
                showFormMessage($form, 'Please select a ZIP file to import.', 'error');
                isValid = false;
            }
            
            // Check if this is a URL import form and validate URL
            if ($urlInput.length && !$urlInput.val().trim()) {
                showFormMessage($form, 'Please enter a valid URL to import.', 'error');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            // Show progress indicator
            var $progress = $form.find('.wsi-import-progress');
            var $progressBar = $progress.find('.wsi-progress-bar-inner');
            var $progressText = $progress.find('.wsi-progress-text');
            
            // Clear any error messages
            $form.find('.wsi-status-message').remove();
            
            $progress.show();
            $progressBar.css('width', '10%');
            $progressText.text(wsiData.uploading || 'Uploading...');
            
            // Disable submit button to prevent multiple submissions
            $form.find('.wsi-submit-button').prop('disabled', true);
            
            // Simulate progress (since we can't track server-side progress)
            simulateProgress($progressBar, $progressText);
            
            // Let the form submit normally
            return true;
        });
    }

    /**
     * Simulate progress for user feedback
     * @param {jQuery} $progressBar The progress bar element
     * @param {jQuery} $progressText The progress text element
     */
    function simulateProgress($progressBar, $progressText) {
        var progress = 10;
        var interval = setInterval(function() {
            progress += Math.floor(Math.random() * 8) + 1;
            
            if (progress >= 65) {
                clearInterval(interval);
                $progressBar.css('width', '65%');
                $progressText.text(wsiData.processing || 'Processing Web Story...');
                return;
            }
            
            $progressBar.css('width', progress + '%');
        }, 600);
    }

    /**
     * Show a status message in the form
     * @param {jQuery} $form The form element
     * @param {string} message The message to display
     * @param {string} type The message type (success, error)
     */
    function showFormMessage($form, message, type) {
        var $statusMessage = $('<div class="wsi-status-message wsi-' + type + '"></div>');
        $statusMessage.text(message);
        $form.prepend($statusMessage);
        
        // Scroll to the message
        $('html, body').animate({
            scrollTop: $statusMessage.offset().top - 100
        }, 500);
    }

})(jQuery);
