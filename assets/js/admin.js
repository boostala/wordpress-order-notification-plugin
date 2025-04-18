jQuery(document).ready(function($) {
    const loginButton = $('#boostala-login');
    const loader = $('.boostala-loader');
    const successMessage = $('.boostala-success');
    const logoutButton = $('#boostala-logout');
    const deviceStatus = $('.boostala-whatsapp-card:eq(1)');

    // Handle logout
    logoutButton.on('click', function() {
        // Add loading class to button
        $(this).addClass('loading').prop('disabled', true);
        
        $.post(wponb_ajax.ajax_url, {
            action: 'wponb_logout',
            nonce: wponb_ajax.nonce
        }, function(response) {
            if (response.success) {
                window.location.reload();
            } else {
                // Remove loading class and enable button
                logoutButton.removeClass('loading').prop('disabled', false);
                alert(wponb_ajax.strings.failed_to_logout);
            }
        });
    });

    // Handle login button click
    loginButton.on('click', function(e) {
        e.preventDefault();
        const loginUrl = $(this).attr('href');
        const urlParams = new URLSearchParams(loginUrl.split('?')[1]);
        const token = urlParams.get('token');
        const shop = urlParams.get('shop');

        // Show loader
        $(this).hide();
        loader.show();

        // Open Boostala login in new window
        const boostalaWindow = window.open(loginUrl, 'Boostala Login', 'width=800,height=600');

        // Check device status periodically
        const checkInterval = setInterval(function() {
            if (boostalaWindow.closed) {
                clearInterval(checkInterval);
                loader.hide();
                loginButton.show();
                return;
            }

            $.ajax({
                url: wponb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'check_device_status',
                    nonce: wponb_ajax.nonce,
                    device_id: shop
                },
                success: function(response) {
                    if (response.success && response.exists) {
                        clearInterval(checkInterval);
                        loader.hide();
                        successMessage.show();
                        boostalaWindow.close();
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                }
            });
        }, 2000);
    });

    // Listen for messages from Boostala
    window.addEventListener('message', function(event) {
        if (event.origin !== 'https://chat.boostala.com') {
            return;
        }

        const data = event.data;
        if (data.type === 'validate_token') {
            $.ajax({
                url: wponb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'validate_token',
                    nonce: wponb_ajax.nonce,
                    device_id: data.device_id,
                    api_key: data.api_key,
                    token: data.token
                },
                success: function(response) {
                    if (response.success) {
                        event.source.postMessage({
                            type: 'token_validated',
                            success: true
                        }, 'https://chat.boostala.com');
                    } else {
                        event.source.postMessage({
                            type: 'token_validated',
                            success: false,
                            message: response.message
                        }, 'https://chat.boostala.com');
                    }
                }
            });
        }
    });
}); 