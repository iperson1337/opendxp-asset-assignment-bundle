opendxp.registerNS('opendxp.plugin.datahub.configuration.assetAssignment.configItem');

opendxp.plugin.datahub.configuration.assetAssignment.configItem = Class.create(opendxp.plugin.datahub.configuration.graphql.configItem, {

    getPanels: function () {
        return [
            this.buildGeneralTab(),
            this.buildSettingsTab(),
            this.buildExecutionTab(),
            this.getPermissions()
        ];
    },

    initialize: function (data, parent) {
        this.parent = parent;
        this.configName = data.name;
        this.data = data.configuration;
        this.userPermissions = data.userPermissions || {update: true, delete: true};
        this.modificationDate = data.modificationDate;
        
        // Set writeable to true if undefined for backwards compatibility
        if (typeof this.data.general.writeable === 'undefined') {
            this.data.general.writeable = true;
        }

        this.tab = Ext.create('Ext.TabPanel', {
            title: this.data.general.name,
            closable: true,
            deferredRender: true,
            forceLayout: true,
            iconCls: "plugin_pimcore_datahub_icon_assetAssignment",
            id: "plugin_pimcore_datahub_configpanel_panel_" + data.name,
            buttons: {
                componentCls: 'plugin_pimcore_datahub_statusbar',
                itemId: 'footer'
            }
        });

        this.tab.add(this.getPanels());
        this.tab.setActiveTab(0);

        this.tab.on("activate", this.tabactivated.bind(this));
        this.tab.on("destroy", this.tabdestroy.bind(this));

        this.parent.configPanel.editPanel.add(this.tab);
        this.parent.configPanel.editPanel.setActiveTab(this.tab);
        this.parent.configPanel.editPanel.updateLayout();

        this.showInfo();
    },

    showInfo: function () {
        var footer = this.tab.getDockedComponent('footer');
        footer.removeAll();
        footer.add('->');

        let saveButtonConfig = {
            text: t("save"),
            iconCls: "opendxp_icon_apply",
            disabled: !this.data.general.writeable || !this.userPermissions.update,
            handler: this.save.bind(this)
        };

        if (!this.data.general.writeable) {
            saveButtonConfig.tooltip = t("config_not_writeable");
        }

        footer.add(saveButtonConfig);
    },

    tabactivated: function () {
        // Empty implementation, can be extended if needed
    },

    tabdestroy: function () {
        // Empty implementation, can be extended if needed
    },

    buildGeneralTab: function () {
        var cfg = this.data;
        
        this.generalForm = new Ext.form.FormPanel({
            title: t('plugin_pimcore_datahub_configpanel_item_general'),
            bodyStyle: 'padding:10px;',
            labelWidth: 200,
            autoScroll: true,
            border: false,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            items: [
                {
                    xtype: 'checkbox',
                    fieldLabel: t('active'),
                    name: 'active',
                    inputValue: true,
                    value: cfg.general && cfg.general.hasOwnProperty('active') ? cfg.general.active : true
                },
                {
                    xtype: 'textfield',
                    fieldLabel: t('type'),
                    name: 'type',
                    value: t('plugin_pimcore_datahub_type_assetAssignment'),
                    readOnly: true
                },
                {
                    xtype: 'textfield',
                    fieldLabel: t('name'),
                    name: 'name',
                    value: cfg.general.name,
                    readOnly: true
                },
                {
                    xtype: 'textarea',
                    fieldLabel: t('description'),
                    name: 'description',
                    height: 100,
                    value: cfg.general.description || ''
                },
                {
                    xtype: 'textfield',
                    fieldLabel: t('group'),
                    name: 'group',
                    value: cfg.general.group || ''
                }
            ]
        });

        return this.generalForm;
    },

    buildSettingsTab: function () {
        var cfg = this.data;
        var hasUpdatePermission = this.userPermissions.update;
        
        // Source Folder Field
        this.sourceFolderField = this.createFolderField(
            'profile[source_folder]',
            (cfg.profile && cfg.profile.source_folder) || '',
            'asset',
            hasUpdatePermission
        );
        
        // Target Folder Field
        this.targetFolderField = this.createFolderField(
            'profile[target_folder]',
            (cfg.profile && cfg.profile.target_folder) || '',
            'asset',
            hasUpdatePermission
        );
        
        // Match Field ComboBox
        this.matchFieldCombo = new Ext.form.ComboBox({
                    fieldLabel: t('plugin_pimcore_asset_assignment_match_field'),
            name: 'profile[match_field]',
            value: (cfg.profile && cfg.profile.match_field) || 'code',
            displayField: 'key',
            valueField: 'key',
            forceSelection: true,
            queryMode: 'local',
            emptyText: t('plugin_pimcore_asset_assignment_select_field'),
            allowBlank: false,
            msgTarget: 'under',
            disabled: !hasUpdatePermission
        });
        
        // Attributes Store
        this.attributeStore = new Ext.data.JsonStore({
            fields: ['key', 'name', 'localized'],
            autoLoad: false,
            proxy: {
                type: 'ajax',
                url: Routing.generate('opendxp_dataimporter_configdataobject_loaddataobjectattributes'),
                extraParams: {
                    system_read: 1
                },
                reader: {
                    type: 'json',
                    rootProperty: 'attributes'
                }
            },
            listeners: {
                load: function(store) {
                    if (cfg.profile && cfg.profile.match_field) {
                        this.matchFieldCombo.setValue(cfg.profile.match_field);
                    }
                }.bind(this)
            }
        });
        
        this.matchFieldCombo.setStore(this.attributeStore);
        
        // Attachment Field ComboBox
        this.attachmentFieldCombo = new Ext.form.ComboBox({
            fieldLabel: t('plugin_pimcore_asset_assignment_attachment_field'),
            name: 'profile[attachment_field]',
            value: (cfg.profile && cfg.profile.attachment_field) || '',
            displayField: 'name',
            valueField: 'key',
            forceSelection: true,
            queryMode: 'local',
            emptyText: t('plugin_pimcore_asset_assignment_select_media_field'),
            allowBlank: false,
            msgTarget: 'under',
            disabled: !hasUpdatePermission,
            listeners: {
                change: function(combo, newValue) {
                    // Check if selected field is imageGallery type
                    var record = combo.getStore().findRecord('key', newValue);
                    if (record) {
                        var fieldName = record.get('name');
                        // Show checkbox only if field contains imageGallery
                        var isImageGallery = fieldName.toLowerCase().includes('imagegallery') || 
                                             fieldName.includes('[imageGallery]');
                        this.clearGalleryCheckbox.setHidden(!isImageGallery);
                    }
                }.bind(this)
            }
        });
        
        // Media Fields Store
        this.mediaFieldsStore = new Ext.data.JsonStore({
            fields: ['key', 'name'],
            autoLoad: false,
            proxy: {
                type: 'ajax',
                url: Routing.generate('iperson1337_assetassignment_assetassignment_loadmediafields'),
                reader: {
                    type: 'json',
                    rootProperty: 'attributes'
                }
            },
            listeners: {
                load: function(store) {
                    if (cfg.profile && cfg.profile.attachment_field) {
                        this.attachmentFieldCombo.setValue(cfg.profile.attachment_field);
                        // Check if current field is imageGallery and show/hide checkbox accordingly
                        var record = store.findRecord('key', cfg.profile.attachment_field);
                        if (record) {
                            var fieldName = record.get('name');
                            var isImageGallery = fieldName.toLowerCase().includes('imagegallery') || 
                                                 fieldName.includes('[imageGallery]');
                            this.clearGalleryCheckbox.setHidden(!isImageGallery);
                        }
                    }
                }.bind(this)
            }
        });
        
        this.attachmentFieldCombo.setStore(this.mediaFieldsStore);
        
        // Clear Gallery Checkbox (hidden by default)
        this.clearGalleryCheckbox = new Ext.form.Checkbox({
            fieldLabel: t('plugin_pimcore_asset_assignment_clear_gallery'),
            name: 'profile[clear_gallery_before_assignment]',
            inputValue: true,
            value: (cfg.profile && cfg.profile.clear_gallery_before_assignment) || false,
            hidden: true,  // Hidden by default
            disabled: !hasUpdatePermission
        });
        
        // DataObject Class ComboBox
        this.dataObjectClassCombo = new Ext.form.ComboBox({
            fieldLabel: t('plugin_pimcore_asset_assignment_dataobject_class'),
            name: 'profile[data_object_class_id]',
            typeAhead: true,
            triggerAction: 'all',
            store: opendxp.globalmanager.get('object_types_store'),
            valueField: 'id',
            displayField: 'text',
            listWidth: 'auto',
            forceSelection: true,
            allowBlank: false,
            emptyText: t('plugin_pimcore_asset_assignment_select_class'),
            msgTarget: 'under',
            disabled: !hasUpdatePermission,
            listeners: {
                change: function(combo, newValue) {
                    // Load attributes for selected class
                    this.attributeStore.proxy.setExtraParam('class_id', newValue);
                    this.attributeStore.load();
                    
                    // Load media fields for selected class
                    this.mediaFieldsStore.proxy.setExtraParam('class_id', newValue);
                    this.mediaFieldsStore.load();
                }.bind(this)
            }
        });
        
        // Set initial value if exists
        if (cfg.profile && cfg.profile.data_object_class_id) {
            this.dataObjectClassCombo.setValue(cfg.profile.data_object_class_id);
            this.attributeStore.proxy.setExtraParam('class_id', cfg.profile.data_object_class_id);
            this.attributeStore.load();
            this.mediaFieldsStore.proxy.setExtraParam('class_id', cfg.profile.data_object_class_id);
            this.mediaFieldsStore.load();
        }
        
        this.settingsForm = new Ext.form.FormPanel({
            title: t('plugin_pimcore_asset_assignment_settings'),
            bodyStyle: 'padding:10px;',
            labelWidth: 200,
            autoScroll: true,
            border: false,
            defaults: {
                labelWidth: 200,
                width: 800,
                allowBlank: false,
                msgTarget: 'under'
            },
            items: [
                {
                    xtype: 'fieldcontainer',
                    fieldLabel: t('plugin_pimcore_asset_assignment_source_folder'),
                    layout: 'hbox',
                    items: [
                        this.sourceFolderField,
                        {
                            xtype: 'button',
                            iconCls: 'opendxp_icon_delete',
                            style: 'margin-left: 5px',
                            disabled: !hasUpdatePermission,
                            handler: function() {
                                this.sourceFolderField.setValue('');
                            }.bind(this)
                        },
                        {
                            xtype: 'button',
                            iconCls: 'opendxp_icon_search',
                            style: 'margin-left: 5px',
                            disabled: !hasUpdatePermission,
                            handler: function() {
                                this.openFolderSelector('asset', this.sourceFolderField);
                            }.bind(this)
                        }
                    ]
                },
                {
                    xtype: 'fieldcontainer',
                    fieldLabel: t('plugin_pimcore_asset_assignment_target_folder'),
                    layout: 'hbox',
                    items: [
                        this.targetFolderField,
                        {
                            xtype: 'button',
                            iconCls: 'opendxp_icon_delete',
                            style: 'margin-left: 5px',
                            disabled: !hasUpdatePermission,
                            handler: function() {
                                this.targetFolderField.setValue('');
                            }.bind(this)
                        },
                        {
                            xtype: 'button',
                            iconCls: 'opendxp_icon_search',
                            style: 'margin-left: 5px',
                            disabled: !hasUpdatePermission,
                            handler: function() {
                                this.openFolderSelector('asset', this.targetFolderField);
                            }.bind(this)
                        }
                    ]
                },
                {
                    xtype: 'combo',
                    fieldLabel: t('plugin_pimcore_asset_assignment_strategy'),
                    name: 'profile[strategy]',
                    value: (cfg.profile && cfg.profile.strategy) || 'create_folder_and_move',
                    store: [
                        ['create_folder_and_move', t('plugin_pimcore_asset_assignment_strategy_create_folder_and_move')],
                        ['move_to_target', t('plugin_pimcore_asset_assignment_strategy_move_to_target')]
                    ],
                    mode: 'local',
                    triggerAction: 'all',
                    editable: false,
                    forceSelection: true,
                    allowBlank: false,
                    msgTarget: 'under',
                    disabled: !hasUpdatePermission
                },
                this.dataObjectClassCombo,
                this.matchFieldCombo,
                this.attachmentFieldCombo,
                this.clearGalleryCheckbox
            ]
        });

        return this.settingsForm;
    },

    createFolderField: function(name, value, elementType, hasUpdatePermission) {
        var hasPermission = hasUpdatePermission !== false; // Default to true if not specified
        
        var field = new Ext.form.TextField({
            name: name,
            value: value || '/',
            fieldCls: 'opendxp_droptarget_input',
            width: 500,
            enableKeyEvents: true,
            readOnly: !hasPermission,
            listeners: {
                render: function (el) {
                    if (!hasPermission) {
                        return; // Don't add drop zone and context menu if no permission
                    }
                    
                    // Add drop zone
                    new Ext.dd.DropZone(el.getEl(), {
                        reference: field,
                        ddGroup: 'element',
                        getTargetFromEvent: function (e) {
                            return this.reference.getEl();
                        },
                        onNodeOver: function (target, dd, e, data) {
                            if (data.records && data.records.length === 1) {
                                var record = data.records[0].data;
                                if (record.elementType === 'asset' && record.type === 'folder') {
                                    return Ext.dd.DropZone.prototype.dropAllowed;
                                }
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },
                        onNodeDrop: function(target, dd, e, data) {
                            if (!opendxp.helpers.dragAndDropValidateSingleItem(data)) {
                                return false;
                            }
                            var record = data.records[0].data;
                            if (record.elementType === 'asset' && record.type === 'folder') {
                                field.setValue(record.path);
                                return true;
                            }
                            return false;
                        }
                    });

                    el.getEl().on('contextmenu', function(e) {
                        var menu = new Ext.menu.Menu();
                        menu.add(new Ext.menu.Item({
                            text: t('empty'),
                            iconCls: 'opendxp_icon_delete',
                            handler: function (item) {
                                item.parentMenu.destroy();
                                field.setValue('');
                            }
                        }));
                        menu.add(new Ext.menu.Item({
                            text: t('search'),
                            iconCls: 'opendxp_icon_search',
                            handler: function (item) {
                                item.parentMenu.destroy();
                                this.openFolderSelector(elementType, field);
                            }.bind(this)
                        }));
                        menu.showAt(e.getXY());
                        e.stopEvent();
                    }.bind(this));
                }.bind(this)
            }
        });
        return field;
    },

    openFolderSelector: function(elementType, targetField) {
        // Открываем только селектор папок Assets
        opendxp.helpers.itemselector(false, function(data) {
            targetField.setValue(data.fullpath);
        }, {
            type: ['asset'],
            subtype: {
                asset: ['folder']
            },
            specific: {}
        }, {});
    },

    buildExecutionTab: function() {
        if (!this.executionPanel) {
            this.executionPanel = new opendxp.plugin.assetAssignment.configuration.execution(
                this.data.general.name,
                this.data.executionConfig || {},
                this
            );
        }
        
        return this.executionPanel.buildPanel();
    },

    getSaveDataArray: function () {
        // Собираем данные из форм
        var generalData = this.generalForm.getForm().getValues();
        var settingsData = this.settingsForm.getForm().getValues();
        
        // Получаем текст класса из комбобокса
        var classRecord = this.dataObjectClassCombo.getStore().findRecord('id', this.dataObjectClassCombo.getValue());
        var className = classRecord ? classRecord.get('text') : '';
        
        // Объединяем данные
        var data = {
            general: {
                name: this.data.general.name,
                type: 'assetAssignment',
                active: generalData.active !== undefined ? generalData.active : true,
                description: generalData.description || '',
                group: generalData.group || '',
                modificationDate: this.modificationDate
            },
            profile: {
                source_folder: settingsData['profile[source_folder]'] || '',
                target_folder: settingsData['profile[target_folder]'] || '',
                data_object_class_id: this.dataObjectClassCombo.getValue(),
                data_object_class: className,
                match_field: this.matchFieldCombo.getValue(),
                attachment_field: this.attachmentFieldCombo.getValue(),
                strategy: settingsData['profile[strategy]'] || 'create_folder_and_move',
                clear_gallery_before_assignment: this.clearGalleryCheckbox.getValue()
            },
            schema: { queryEntities: [], mutationEntities: [], specialEntities: [] },
            permissions: this.getPermissionsSaveData()
        };

        return data;
    },

    getSaveData: function () {
        return Ext.encode(this.getSaveDataArray());
    },

    save: function () {
        var saveData = this.getSaveData();

        Ext.Ajax.request({
            url: '/admin/pimcoredatahub/config/save',
            method: 'POST',
            params: {
                data: saveData,
                modificationDate: this.modificationDate
            },
            success: function (response) {
                var d = Ext.decode(response.responseText);
                if (d && d.success) {
                    this.modificationDate = d.modificationDate;
                    opendxp.helpers.showNotification(t('success'), t('saved_successfully'), 'success');
                } else if (d && d.permissionError) {
                    opendxp.helpers.showNotification(t("error"), t("plugin_pimcore_datahub_configpanel_item_saveerror_permissions"), "error");
                    this.tab.setActiveTab(this.tab.items.length-1);
                } else {
                    Ext.Msg.alert(t('error'), d && d.message ? d.message : 'Save failed');
                }
            }.bind(this),
            failure: function () {
                Ext.Msg.alert(t('error'), 'HTTP error while saving');
            }
        });
    }
});

