jQuery(document).ready(function($) {
    function processPNR(pnr) {
        if (!pnr || pnr.length !== 6) {
            $('#apt-scan-result').show().removeClass('apt-scan-success').addClass('apt-scan-error').html('Please enter a valid 6-character PNR.');
            return;
        }

        $('#apt-scan-result').show().removeClass('apt-scan-success apt-scan-error').html('Processing ticket...');
        $('#apt-pnr-input').val('').prop('disabled', true);
        $('#apt-btn-check-pnr').prop('disabled', true);
        $('#apt-btn-scan-again').hide();

        $.ajax({
            url: apt_scanner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_scan_ticket',
                nonce: apt_scanner_ajax.nonce,
                pnr: pnr
            },
            success: function(response) {
                if (response.success) {
                    $('#apt-scan-result').addClass('apt-scan-success').html(response.data);
                } else {
                    $('#apt-scan-result').addClass('apt-scan-error').html('Error: ' + response.data);
                }
                $('#apt-btn-scan-again').show();
            },
            error: function() {
                $('#apt-scan-result').addClass('apt-scan-error').html('Server error communicating with check-in system.');
                $('#apt-btn-scan-again').show();
            }
        });
    }

    $('#apt-btn-check-pnr').on('click', function(e) {
        e.preventDefault();
        processPNR($('#apt-pnr-input').val().trim());
    });

    $('#apt-pnr-input').on('keypress', function(e) {
        if (e.which == 13) {
            e.preventDefault();
            processPNR($(this).val().trim());
        }
    });

    $('#apt-btn-scan-again').on('click', function(e) {
        e.preventDefault();
        $('#apt-pnr-input').prop('disabled', false).focus();
        $('#apt-btn-check-pnr').prop('disabled', false);
        $('#apt-scan-result').hide().removeClass('apt-scan-success apt-scan-error').html('');
        $(this).hide();
    });

    // Initialize Calendar if element exists
    if (document.getElementById('apt-calendar')) {
        var calendarEl = document.getElementById('apt-calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            events: typeof aptCalendarEvents !== 'undefined' ? aptCalendarEvents : [],
            headerToolbar: {
                left: 'prev,next',
                center: 'title',
                right: 'today'
            },
            dateClick: function(info) {
                fetchOrdersForDate(info.dateStr);
            }
        });
        calendar.render();

        $('#apt-btn-refresh-calendar').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Refreshing...');

            $.ajax({
                url: apt_scanner_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'apt_get_calendar_events',
                    nonce: apt_scanner_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        calendar.setOption('events', response.data);
                    } else {
                        alert('Could not refresh calendar: ' + response.data);
                    }
                },
                error: function() {
                    alert('Server error while refreshing calendar.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Refresh Calendar');
                }
            });
        });
    }

    function fetchOrdersForDate(dateStr) {
        $('#apt-selected-date').text(dateStr);
        $('#apt-order-details').show();
        $('.apt-tab-content').html('<p>Loading orders...</p>');

        $.ajax({
            url: apt_scanner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_get_orders_for_date',
                nonce: apt_scanner_ajax.nonce,
                date: dateStr
            },
            success: function(response) {
                if (response.success) {
                    renderOrdersTable('morning', response.data.morning);
                    renderOrdersTable('afternoon', response.data.afternoon);
                    renderOrdersTable('evening', response.data.evening);
                } else {
                    $('.apt-tab-content').html('<p>Error loading orders: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('.apt-tab-content').html('<p>Error communicating with server.</p>');
            }
        });
    }

    function renderOrdersTable(slot, orders) {
        var html = '';
        if (!orders || orders.length === 0) {
            html = '<p>No orders for this slot.</p>';
        } else {
            html += '<div class="apt-orders-table-wrapper">';
            html += '<table class="apt-orders-table">';
            html += '<thead><tr><th>Order No</th><th>PNR</th><th>Adults</th><th>Child (6-12)</th><th>Child (Under 6)</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead>';
            html += '<tbody>';
            for (var i = 0; i < orders.length; i++) {
                var o = orders[i];
                html += '<tr>';
                html += '<td>' + o.order_no + '</td>';
                html += '<td>' + o.pnr + '</td>';
                html += '<td>' + o.adults + '</td>';
                html += '<td>' + o.child_6_12 + '</td>';
                html += '<td>' + o.child_under_6 + '</td>';
                html += '<td>' + (o.phone || '') + '</td>';
                html += '<td>' + (o.email || '') + '</td>';
                html += '<td>' + (o.status === 'used' ? '<span style="color:red;font-weight:bold;">USED</span>' : '<span style="color:green;font-weight:bold;">VALID</span>') + '</td>';
                html += '</tr>';
            }
            html += '</tbody></table>';
            html += '</div>';
        }
        $('#tab-' + slot).html(html);
    }

    // Tab switching logic
    $('.apt-tab-btn').on('click', function(e) {
        e.preventDefault();
        $('.apt-tab-btn').removeClass('active');
        $(this).addClass('active');

        var tabId = $(this).data('tab');
        $('.apt-tab-content').hide().removeClass('active');
        $('#tab-' + tabId).show().addClass('active');
    });
});
