jQuery(document).ready(function($) {
    // Handle template form submission
    $('#template-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            action: 'wponb_save_template',
            nonce: wponb_templates.nonce,
            template_id: $('input[name="template_id"]').val(),
            template_name: $('#template_name').val(),
            template_content: $('#template_content').val(),
            template_status: $('#template_status').val()
        };

        $.post(wponb_templates.ajax_url, formData, function(response) {
            if (response.success) {
                alert(response.data.message);
                window.location.href = 'admin.php?page=boostala-templates';
            } else {
                alert(response.data.message);
            }
        });
    });

   
}); 