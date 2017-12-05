window.rcmail && rcmail.addEventListener('init', function(evt) {
    // It will add or remove factors available for selection in the UI.
    rcmail.addEventListener('plugin.save_success', function(data) {
        var factorId = data['id'];
        var factorType = factorId.substring(0, factorId.indexOf(":"));
        if (data['active']) { // A new factor has been just added.
            $("#kolab2fa-add").find("option[value='" + factorType + "']").remove();
        } else { // An existing factor has been removed.
            var factorTypeLabel = rcmail.get_label('kolab_2fa.' + factorType);
            $("#kolab2fa-add").append('<option value="'+factorType+'">'+factorTypeLabel+'</option>')

        }
    });
});