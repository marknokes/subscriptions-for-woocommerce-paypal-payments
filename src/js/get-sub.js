var ppsfwoo_redirected = false;
function ppsfwooSendAjaxRequest() {
    if(false === ppsfwoo_redirected) {
        jQuery.ajax({
            url: '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                'action': 'ppsfwoo_admin_ajax_callback',
                'method': 'ppsfwoo_get_sub',
                'id'    : ppsfwoo_ajax_var.subs_id
            },
            success: function(response) {
                if("false" !== response) {
                    ppsfwoo_redirected = true;
                    location.href = response
                }
            }
        });
    }
}
setInterval(ppsfwooSendAjaxRequest, 1000);