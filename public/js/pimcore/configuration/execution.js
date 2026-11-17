opendxp.registerNS('opendxp.plugin.assetAssignment.configuration.execution');

opendxp.plugin.assetAssignment.configuration.execution = Class.create({

    configName: '',
    data: {},
    configItemRootContainer: null,
    updateHandle: null,
    // Опрашиваем сервер только пока идёт выполнение: простаивающая вкладка Data Hub
    // не должна слать запросы. Статус перечитывается при открытии панели и возврате на вкладку.
    running: false,
    // После «Старт» воркер не сразу отмечает выполнение (сначала группирует файлы) —
    // до этого момента опрашиваем, иначе прогресс не появится.
    awaitingStartUntil: 0,
    destroyed: false,
    visibilityHandler: null,
    runningExecutionsListener: null,

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
                listeners: {
                    activate: this.updateProgress.bind(this),
                    destroy: this.destroy.bind(this)
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

            this.visibilityHandler = function () {
                if (!document.hidden && (this.running || this.isAwaitingStart())) {
                    this.updateProgress();
                }
            }.bind(this);
            document.addEventListener('visibilitychange', this.visibilityHandler);
            this.subscribeToRunningExecutions();

            // Initial status check; further polling only while running
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
                    this.awaitingStartUntil = Date.now() + 120000;
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

    getRunningExecutionsEvent: function() {
        var config = opendxp.plugin.datahub && opendxp.plugin.datahub.config;
        return (config && config.prototype.runningExecutionsEvent) || 'opendxp.datahub.runningExecutions';
    },

    /**
     * Индикатор «Идёт выполнение» Data Hub и так опрашивает сервер (только при видимой панели).
     * Если он видит прогон этого конфига (запущен из другой вкладки или другим пользователем),
     * а панель простаивает — включаем свой подробный опрос.
     */
    subscribeToRunningExecutions: function() {
        var settings = opendxp.settings['data-hub-running-executions'];
        if (!settings || !settings.enabled) {
            return;
        }

        this.runningExecutionsListener = function(event) {
            var items = (event.detail && event.detail.items) || [];
            var isListed = items.some(function(item) {
                return item.name === this.configName;
            }.bind(this));

            if (isListed && !this.running && !this.updateHandle) {
                this.updateProgress();
            }
        }.bind(this);
        document.addEventListener(this.getRunningExecutionsEvent(), this.runningExecutionsListener);
    },

    isAwaitingStart: function() {
        return Date.now() < this.awaitingStartUntil;
    },

    scheduleNextUpdate: function(delay) {
        // Hidden browser tab: no polling, visibilityHandler resumes it
        if (this.destroyed || document.hidden) {
            return;
        }
        this.updateHandle = setTimeout(this.updateProgress.bind(this), delay);
    },

    updateProgress: function() {
        clearTimeout(this.updateHandle);
        this.updateHandle = null;

        if (this.destroyed) {
            return;
        }

        Ext.Ajax.request({
            url: Routing.generate('iperson1337_assetassignment_assetassignment_checkexecutionprogress'),
            method: 'GET',
            params: {
                config_name: this.configName
            },
            success: function(response) {
                if (this.destroyed) {
                    return;
                }

                var data = Ext.decode(response.responseText);
                this.running = !!data.isRunning;
                if (this.running) {
                    this.awaitingStartUntil = 0;
                }

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
                    
                    this.scheduleNextUpdate(1000);
                } else {
                    this.progressBar.hide();
                    this.cancelButtonContainer.hide();
                    
                    // Enable start button when script is not running
                    this.buttonFieldContainer.down('button').setDisabled(false);
                    this.buttonFieldContainer.down('button').setText(t('plugin_pimcore_asset_assignment_execution_start'));
                    
                    this.progressLabel.setHtml('<b>' + t('plugin_pimcore_asset_assignment_execution_not_running') + '</b>');

                    if (this.isAwaitingStart()) {
                        this.scheduleNextUpdate(2000);
                    }
                }

            }.bind(this),
            failure: function() {
                // Retry only while an execution is being watched
                if (this.running || this.isAwaitingStart()) {
                    this.scheduleNextUpdate(5000);
                }
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
        this.destroyed = true;
        this.running = false;
        this.awaitingStartUntil = 0;
        clearTimeout(this.updateHandle);
        this.updateHandle = null;

        if (this.visibilityHandler) {
            document.removeEventListener('visibilitychange', this.visibilityHandler);
            this.visibilityHandler = null;
        }

        if (this.runningExecutionsListener) {
            document.removeEventListener(this.getRunningExecutionsEvent(), this.runningExecutionsListener);
            this.runningExecutionsListener = null;
        }
    }
});

