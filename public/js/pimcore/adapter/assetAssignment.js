opendxp.registerNS("opendxp.plugin.datahub.adapter.assetAssignment");

opendxp.plugin.datahub.adapter.assetAssignment = Class.create(opendxp.plugin.datahub.adapter.abstract, {
    
    openConfiguration: function (id) {
        var existingPanel = Ext.getCmp("plugin_pimcore_datahub_configpanel_panel_" + id);
        if (existingPanel) {
            this.configPanel.editPanel.setActiveTab(existingPanel);
            return;
        }

        Ext.Ajax.request({
            url: '/admin/opendxpdatahub/config/get',
            params: {
                name: id
            },
            success: function (response) {
                var data = Ext.decode(response.responseText);
                new opendxp.plugin.datahub.configuration.assetAssignment.configItem(data, this);
                opendxp.layout.refresh();
            }.bind(this)
        });
    }
});
