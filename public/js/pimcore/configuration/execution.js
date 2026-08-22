opendxp.registerNS('opendxp.plugin.assetAssignment.configuration.execution');

opendxp.plugin.assetAssignment.configuration.execution = Class.create({

    configName: '',
    data: {},
    configItemRootContainer: null,
    updateHandle: null,

    initialize: function(configName, data, configItemRootContainer) {
        this.configName = configName;
        this.data = data || {};
        this.configItemRootContainer = configItemRootContainer;
    },

    buildPanel: function() {
        if (!this.form) {
            // Manual Execution Button
            this.buttonFieldContainer = Ext.create('Ext.form.FieldContainer', {
                fieldLabel: t('plugin_pimcore_asset_assignment_execution_manual'),
                items: [
                    {
                        xtype: 'button',
                        width: 165,
                        text: t('plugin_pimcore_asset_assignment_execution_start'),
                        handler: this.startExecution.bind(this)
                    }
                ]
            });

            // Progress Bar
            this.progressBar = Ext.create('Ext.ProgressBar', {
                text: t('plugin_pimcore_asset_assignment_execution_status'),
                hidden: true,
                style: 'margin-top: 10px;'
            });

            // Progress Label
            this.progressLabel = Ext.create('Ext.form.Label', {
                html: '<b>' + t('plugin_pimcore_asset_assignment_execution_not_running') + '</b>',
                style: 'display: block; margin-top: 10px;'
            });

            // Cancel Button
            this.cancelButtonContainer = Ext.create('Ext.form.FieldContainer', {
                hidden: true,
                style: 'margin-top: 10px;',
                items: [
                    {
                        xtype: 'button',
                        text: t('plugin_pimcore_asset_assignment_execution_cancel'),
                        iconCls: 'opendxp_icon_stop',
                        handler: this.cancelExecution.bind(this)
                    }
                ]
            });

            // Main Form
            this.form = Ext.create('Ext.form.FormPanel', {
                title: t('plugin_pimcore_asset_assignment_execution'),
                bodyStyle: 'padding:10px;',
                border: false,
                autoScroll: true,
                defaults: {
                    labelWidth: 200
                },
                items: [
                    {
                        xtype: 'fieldset',
                        title: t('plugin_pimcore_asset_assignment_execution_manual'),
                        collapsible: false,
                        items: [
                            this.buttonFieldContainer
                        ]
                    },
                    {
                        xtype: 'fieldset',
                        title: t('plugin_pimcore_asset_assignment_execution_status'),
                        collapsible: false,
                        items: [
                            this.progressLabel,
                            this.progressBar,
                            this.cancelButtonContainer
                        ]
                    }
                ]
            });

            // Start progress monitoring
            this.updateProgress();
        }

        return this.form;
    },

    startExecution: function() {
        this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_starting'));
        this.buttonFieldContainer.down('button').setDisabled(true);

        Ext.Ajax.request({
            url: Routing.generate('iperson1337_assetassignment_assetassignment_startexecution'),
            method: 'POST',
            params: {
                config_name: this.configName
            },
            success: function(response) {
                var data = Ext.decode(response.responseText);
                
                this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_start'));
                this.buttonFieldContainer.down('button').setDisabled(false);

                if (data.success) {
                    opendxp.helpers.showNotification(
                        t('success'),
                        t('plugin_pimcore_asset_assignment_execution_success'),
                        'success'
                    );
                    this.updateProgress();
                } else {
                    opendxp.helpers.showNotification(
                        t('error'),
                        data.message || t('plugin_pimcore_asset_assignment_execution_error'),
                        'error'
                    );
                }
            }.bind(this),
            failure: function() {
                this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_start'));
                this.buttonFieldContainer.down('button').setDisabled(false);
                
                opendxp.helpers.showNotification(
                    t('error'),
                    t('plugin_pimcore_asset_assignment_execution_error'),
                    'error'
                );
            }.bind(this)
        });
    },

    updateProgress: function() {
        clearTimeout(this.updateHandle);
        
        Ext.Ajax.request({
            url: Routing.generate('iperson1337_assetassignment_assetassignment_checkexecutionprogress'),
            method: 'GET',
            params: {
                config_name: this.configName
            },
            success: function(response) {
                var data = Ext.decode(response.responseText);

                if (data.isRunning) {
                    this.progressBar.show();
                    this.cancelButtonContainer.show();
                    
                    // Disable start button when script is running
                    this.buttonFieldContainer.down('button').setDisabled(true);
                    this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_starting'));
                    
                    var progressText = data.processedItems + '/' + data.totalItems + ' ' + 
                        t('plugin_pimcore_asset_assignment_execution_processed');
                    
                    if (data.movedFiles > 0 || data.attachedFiles > 0) {
                        progressText += ' (' + data.movedFiles + ' moved, ' + data.attachedFiles + ' attached)';
                    }
                    
                    this.progressBar.updateProgress(data.progress, progressText);
                    this.progressLabel.setHtml(t('plugin_pimcore_asset_assignment_execution_current_progress'));
                    
                    // Poll more frequently when running (every 1 second for real-time updates)
                    this.updateHandle = setTimeout(this.updateProgress.bind(this), 1000);
                } else {
                    this.progressBar.hide();
                    this.cancelButtonContainer.hide();
                    
                    // Enable start button when script is not running
                    this.buttonFieldContainer.down('button').setDisabled(false);
                    this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_start'));
                    
                    this.progressLabel.setHtml('<b>' + t('plugin_pimcore_asset_assignment_execution_not_running') + '</b>');
                    
                    // Poll less frequently when not running (every 5 seconds)
                    this.updateHandle = setTimeout(this.updateProgress.bind(this), 5000);
                }

            }.bind(this),
            failure: function() {
                // Retry on failure
                this.updateHandle = setTimeout(this.updateProgress.bind(this), 5000);
            }.bind(this)
        });
    },

    cancelExecution: function() {
        Ext.MessageBox.confirm(
            t('plugin_pimcore_asset_assignment_execution_cancel'),
            t('plugin_pimcore_asset_assignment_execution_cancel_confirm'),
            function(btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: Routing.generate('iperson1337_assetassignment_assetassignment_cancelexecution'),
                        method: 'PUT',
                        params: {
                            config_name: this.configName
                        },
                        success: function(response) {
                            var data = Ext.decode(response.responseText);
                            
                            if (data.success) {
                                opendxp.helpers.showNotification(
                                    t('success'),
                                    t('plugin_pimcore_asset_assignment_execution_cancel_successful'),
                                    'success'
                                );
                                // Enable start button after successful cancellation
                                this.buttonFieldContainer.down('button').setDisabled(false);
                                this.updateProgress();
                            } else {
                                opendxp.helpers.showNotification(
                                    t('error'),
                                    data.message || t('plugin_pimcore_asset_assignment_execution_error'),
                                    'error'
                                );
                            }
                        }.bind(this),
                        failure: function() {
                            opendxp.helpers.showNotification(
                                t('error'),
                                t('plugin_pimcore_asset_assignment_execution_error'),
                                'error'
                            );
                        }
                    });
                }
            }.bind(this)
        );
    },

    destroy: function() {
        // Clear update interval when panel is destroyed
        if (this.updateHandle) {
            clearTimeout(this.updateHandle);
        }
    }
});

