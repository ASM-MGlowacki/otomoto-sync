/**
 * CMU Otomoto Integration - Admin JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        CMU_OtomotoAdmin.init();
    });

    // Main admin object
    window.CMU_OtomotoAdmin = {
        
        // Configuration
        config: {
            autoRefreshInterval: 30000, // 30 seconds
            maxAutoRefreshAttempts: 120, // 1 hour max
            ajaxUrl: cmu_otomoto_admin.ajax_url || ajaxurl,
            nonce: cmu_otomoto_admin.nonce || ''
        },

        // State tracking
        state: {
            autoRefreshAttempts: 0,
            isAutoRefreshing: false,
            loadingElements: new Set()
        },

        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.initAutoRefresh();
            this.enhanceFormSubmissions();
            console.log('CMU Otomoto Admin initialized');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Enhanced confirmation dialogs
            $('form[action*="cmu_manual_sync"]').on('submit', this.handleSyncFormSubmit.bind(this));
            $('.cmu-otomoto-metabox .button').on('click', this.handleMetaboxButtonClick.bind(this));
            
            // Manual refresh button
            $(document).on('click', '.cmu-refresh-status', this.handleManualRefresh.bind(this));
            
            // Stop auto-refresh on visibility change
            $(document).on('visibilitychange', this.handleVisibilityChange.bind(this));
        },

        /**
         * Handle sync form submissions with enhanced confirmation
         */
        handleSyncFormSubmit: function(e) {
            const $form = $(e.target);
            const syncType = $form.find('input[name="sync_type"]').val();
            const buttonText = $form.find('input[type="submit"]').val();
            
            let confirmMessage = '';
            let extraWarning = '';
            
            switch(syncType) {
                case 'batch':
                    confirmMessage = 'Czy na pewno chcesz rozpocząć synchronizację wsadową?\n\nProceso może potrwać kilka minut i będzie wykonywany w tle.';
                    break;
                case 'manual':
                    confirmMessage = 'Czy na pewno chcesz uruchomić synchronizację manualną?\n\nTo może zająć kilka minut i może wpłynąć na wydajność strony.';
                    break;
                case 'force':
                    confirmMessage = 'UWAGA: WYMUSZONE ODŚWIEŻENIE NADPISZE WSZYSTKIE MANUALNE ZMIANY!\n\nCzy na pewno chcesz kontynuować?\n\nTa operacja nie może zostać cofnięta.';
                    extraWarning = 'Ta operacja jest nieodwracalna!';
                    break;
            }
            
            if (confirmMessage && !confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
            
            if (extraWarning && !confirm(extraWarning + '\n\nCzy naprawdę chcesz kontynuować?')) {
                e.preventDefault();
                return false;
            }
            
            // Add loading state
            this.addLoadingState($form);
            
            // Disable other sync buttons temporarily
            $('form[action*="cmu_manual_sync"] input[type="submit"]').prop('disabled', true);
            
            return true;
        },

        /**
         * Handle metabox button clicks
         */
        handleMetaboxButtonClick: function(e) {
            const $button = $(e.target);
            
            if ($button.hasClass('button-primary')) {
                // Refresh data button
                const confirmMsg = 'Czy na pewno chcesz odświeżyć dane tego wpisu z Otomoto?\n\nZmiany wprowadzone manualnie mogą zostać nadpisane.';
                if (!confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Add loading state to the metabox
            this.addLoadingState($button.closest('.cmu-otomoto-metabox'));
        },

        /**
         * Initialize auto-refresh for status page
         */
        initAutoRefresh: function() {
            // Only on the settings page
            if ($('.cmu-otomoto-admin-page').length === 0) {
                return;
            }
            
            this.state.isAutoRefreshing = true;
            this.scheduleStatusRefresh();
            
            // Add manual refresh button
            this.addManualRefreshButton();
        },

        /**
         * Add manual refresh button to status section
         */
        addManualRefreshButton: function() {
            const $statusSection = $('.cmu-otomoto-status-section h2');
            if ($statusSection.length > 0) {
                const refreshButton = $('<button type="button" class="button button-small cmu-refresh-status" style="margin-left: 10px;">↻ Odśwież Status</button>');
                $statusSection.append(refreshButton);
            }
        },

        /**
         * Schedule automatic status refresh
         */
        scheduleStatusRefresh: function() {
            if (!this.state.isAutoRefreshing) {
                return;
            }
            
            setTimeout(() => {
                if (this.state.autoRefreshAttempts < this.config.maxAutoRefreshAttempts) {
                    this.refreshStatus();
                    this.state.autoRefreshAttempts++;
                    this.scheduleStatusRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            }, this.config.autoRefreshInterval);
        },

        /**
         * Refresh status information
         */
        refreshStatus: function() {
            if (!this.state.isAutoRefreshing) {
                return;
            }
            
            const $statusSection = $('.cmu-otomoto-status-section');
            
            // Add subtle loading indicator
            $statusSection.addClass('cmu-refreshing');
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cmu_otomoto_get_status',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        this.updateStatusDisplay(response.data);
                        console.log('Status refreshed automatically');
                    }
                },
                error: (xhr, status, error) => {
                    console.log('Status refresh failed:', error);
                },
                complete: () => {
                    $statusSection.removeClass('cmu-refreshing');
                }
            });
        },

        /**
         * Handle manual refresh button click
         */
        handleManualRefresh: function(e) {
            e.preventDefault();
            const $button = $(e.target);
            
            $button.prop('disabled', true).text('Odświeżanie...');
            
            this.refreshStatus();
            
            setTimeout(() => {
                $button.prop('disabled', false).text('↻ Odśwież Status');
            }, 2000);
        },

        /**
         * Update status display with new data
         */
        updateStatusDisplay: function(data) {
            // Update batch status indicator
            if (data.batch_status && data.batch_status.cycle_status) {
                const $indicator = $('.cmu-otomoto-status-indicator');
                const status = data.batch_status.cycle_status;
                
                // Remove old status classes
                $indicator.removeClass('status-running status-completed status-error status-idle');
                
                // Add new status class and text
                let statusText = 'Nieznany';
                let statusClass = 'status-idle';
                
                switch(status) {
                    case 'running':
                        statusClass = 'status-running';
                        statusText = 'W trakcie';
                        break;
                    case 'completed':
                        statusClass = 'status-completed';
                        statusText = 'Zakończony';
                        break;
                    case 'error':
                        statusClass = 'status-error';
                        statusText = 'Błąd';
                        break;
                    case 'idle':
                    default:
                        statusClass = 'status-idle';
                        statusText = 'Bezczynny';
                        break;
                }
                
                $indicator.addClass(statusClass).text(statusText);
            }
            
            // Update progress if available
            if (data.batch_status && data.batch_status.current_page && data.batch_status.total_pages) {
                const progressText = `Strona ${data.batch_status.current_page} z ${data.batch_status.total_pages}`;
                $('th:contains("Postęp cyklu:")').next('td').text(progressText);
            }
        },

        /**
         * Stop auto-refresh
         */
        stopAutoRefresh: function() {
            this.state.isAutoRefreshing = false;
            console.log('Auto-refresh stopped after maximum attempts');
        },

        /**
         * Handle page visibility change
         */
        handleVisibilityChange: function() {
            if (document.hidden) {
                this.state.isAutoRefreshing = false;
            } else {
                // Resume auto-refresh when page becomes visible again
                this.state.autoRefreshAttempts = 0;
                this.state.isAutoRefreshing = true;
                this.scheduleStatusRefresh();
            }
        },

        /**
         * Add loading state to element
         */
        addLoadingState: function($element) {
            if (!$element || $element.length === 0) {
                return;
            }
            
            const elementId = $element.attr('id') || Math.random().toString(36).substr(2, 9);
            $element.attr('id', elementId);
            
            $element.addClass('cmu-loading');
            
            // Add spinner if not exists
            if ($element.find('.cmu-spinner').length === 0) {
                $element.append('<div class="cmu-spinner"></div>');
            }
            
            this.state.loadingElements.add(elementId);
        },

        /**
         * Remove loading state from element
         */
        removeLoadingState: function($element) {
            if (!$element || $element.length === 0) {
                return;
            }
            
            const elementId = $element.attr('id');
            
            $element.removeClass('cmu-loading');
            $element.find('.cmu-spinner').remove();
            
            if (elementId) {
                this.state.loadingElements.delete(elementId);
            }
        },

        /**
         * Enhance form submissions with loading states
         */
        enhanceFormSubmissions: function() {
            // Auto-remove loading states on page unload
            $(window).on('beforeunload', () => {
                this.state.loadingElements.forEach(elementId => {
                    const $element = $('#' + elementId);
                    if ($element.length > 0) {
                        this.removeLoadingState($element);
                    }
                });
            });
        }
    };

})(jQuery); 