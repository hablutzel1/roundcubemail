/**
 * SIE user support plugin
 */

window.rcmail && rcmail.addEventListener('init', function(args) { 
    rcmail.register_command('plugin.activate', function() {rcmail.activate()}, true);
    $('#linkcode').click(function(e) {
        e.preventDefault();
        $('#_secret').show();
        rcmail.copyToClipboard($('#_secret').val());
    });
    // for kolab2fa plugin
    if ($('#_timestamp').val() == '') {
        var time = Math.round(new Date().getTime() / 1000);
        $('#_timestamp').val(time);
    }
});

rcube_webmail.prototype.activate = function()
{
    $("#_activatebutton").attr('disabled','disabled');
    $("#message").empty()
    rcmail.set_busy(true, 'loading');
    rcmail.gui_objects.activationform.submit();
}

rcube_webmail.prototype.copyToClipboard = function(text)
{
    var temp = document.createElement("textarea");
    temp.value = text;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand("Copy");
    temp.remove();
    setTimeout(() => alert(rcmail.gettext('alreadycopied','sie_user_support')), 50);
}

