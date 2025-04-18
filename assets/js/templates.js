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

    // Handle status toggle
    $('.toggle-status').on('click', function() {
        const button = $(this);
        const templateId = button.data('id');
        const currentStatus = button.data('status');
        const newStatus = currentStatus ? 0 : 1;

        const data = {
            action: 'wponb_update_template_status',
            nonce: wponb_templates.nonce,
            template_id: templateId,
            status: newStatus
        };

        $.post(wponb_templates.ajax_url, data, function(response) {
            if (response.success) {
                // Update button text and data-status
                button.data('status', newStatus);
                button.text(newStatus ? 'Disable' : 'Enable');
                
                // Update status span
                const statusSpan = button.closest('tr').find('.template-status');
                statusSpan.removeClass('active inactive')
                    .addClass(newStatus ? 'active' : 'inactive')
                    .text(newStatus ? 'Active' : 'Inactive');
            } else {
                alert(response.data.message);
            }
        });
    });
}); 