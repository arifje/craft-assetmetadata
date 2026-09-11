(function ($) {
  if (typeof Craft.AssetMetadata === typeof undefined) {
    Craft.AssetMetadata = {};
  }

  /**
   * Asset Metadata field: refreshes the subfield inputs with freshly extracted metadata.
   */
  Craft.AssetMetadata.Field = Garnish.Base.extend({
    $field: null,
    $refreshBtn: null,
    $spinner: null,
    busy: false,

    init: function (settings) {
      this.setSettings(settings);

      // Craft wraps a field’s input in a container with the ID `<inputId>-field`
      this.$field = $('#' + this.settings.id + '-field');

      if (!this.$field.length) {
        this.$field = $('#' + this.settings.id).closest('.field');
      }

      this.$refreshBtn = this.$field.find('.assetmetadata-refresh');
      this.$spinner = this.$field.find('.assetmetadata-field .spinner');

      this.addListener(this.$refreshBtn, 'activate', 'updateField');
    },

    updateField: function () {
      if (this.busy) {
        return;
      }

      this.busy = true;
      this.$spinner.removeClass('hidden');
      this.$refreshBtn.addClass('disabled');

      const data = {
        fieldId: this.settings.fieldId,
        elementId: this.settings.elementId,
        siteId: this.settings.siteId,
      };

      Craft.sendActionRequest('POST', 'asset-metadata/metadata/get-field-value', {data})
        .then((response) => {
          // The values are keyed by subfield ID, so they arrive as an object rather than an array
          const values = (response.data && response.data.value) || {};

          Object.keys(values).forEach((subfieldId) => {
            const $input = this.$field.find('input[name="' + this.settings.name + '[' + subfieldId + ']"]');
            $input.val(values[subfieldId]).trigger('change');
          });
        })
        .catch((error) => {
          const message = (error.response && error.response.data && error.response.data.message) ||
            Craft.t('asset-metadata', 'Could not refresh the metadata.');
          Craft.cp.displayError(message);
        })
        .finally(() => {
          this.$spinner.addClass('hidden');
          this.$refreshBtn.removeClass('disabled');
          this.busy = false;
        });
    },
  });
})(jQuery);
