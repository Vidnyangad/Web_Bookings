jQuery(document).ready(function($) {
    let selectedDate = '';
    let selectedSlot = '';

    $('#apt-btn-next-1').on('click', function(e) {
        e.preventDefault();
        let adults = parseInt($('#apt-qty-adult').val()) || 0;
        let children = parseInt($('#apt-qty-child').val()) || 0;
        let infants = parseInt($('#apt-qty-infant').val()) || 0;

        if (adults + children + infants === 0) {
            alert('Please select at least one ticket.');
            return;
        }

        $('#apt-step-1').hide();
        $('#apt-step-2').show();
    });

    $('#apt-btn-back-2').on('click', function(e) {
        e.preventDefault();
        $('#apt-step-2').hide();
        $('#apt-step-1').show();
        // Reset selections
        selectedDate = '';
        selectedSlot = '';
        $('#apt-visit-date').val('');
        $('#apt-slots-container').hide();
        $('.apt-slot-btn').removeClass('selected').prop('disabled', false);
        $('#apt-btn-book').hide();
        $('#apt-availability-message').html('');
    });

    $('#apt-visit-date').on('change', function() {
        selectedDate = $(this).val();
        selectedSlot = '';
        $('.apt-slot-btn').removeClass('selected');
        $('#apt-btn-book').hide();

        if (selectedDate) {
            $('#apt-slots-container').show();
            checkAvailability();
        } else {
            $('#apt-slots-container').hide();
        }
    });

    $('.apt-slot-btn').on('click', function(e) {
        e.preventDefault();
        if ($(this).prop('disabled')) return;

        $('.apt-slot-btn').removeClass('selected');
        $(this).addClass('selected');
        selectedSlot = $(this).data('slot');
        $('#apt-btn-book').show();
    });

    function checkAvailability() {
        let adults = parseInt($('#apt-qty-adult').val()) || 0;

        $('#apt-availability-message').html('Checking availability...');
        $('.apt-slot-btn').prop('disabled', true); // Disable buttons while checking

        $.ajax({
            url: apt_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_check_availability',
                nonce: apt_ajax.nonce,
                date: selectedDate,
                adults: adults
            },
            success: function(response) {
                if (response.success) {
                    $('#apt-availability-message').html('');
                    let availability = response.data;

                    $('.apt-slot-btn').each(function() {
                        let slot = $(this).data('slot');
                        if (availability[slot] && availability[slot].bookable !== false && availability[slot].available >= adults) {
                            $(this).prop('disabled', false);
                        } else {
                            $(this).prop('disabled', true);
                        }
                    });
                } else {
                    $('#apt-availability-message').html('<span class="apt-error">' + response.data + '</span>');
                }
            },
            error: function() {
                $('#apt-availability-message').html('<span class="apt-error">Error checking availability. Please try again.</span>');
            }
        });
    }

    $('#apt-btn-book').on('click', function(e) {
        e.preventDefault();

        if (!selectedDate || !selectedSlot) {
            alert('Please select a date and time slot.');
            return;
        }

        let adults = parseInt($('#apt-qty-adult').val()) || 0;
        let children = parseInt($('#apt-qty-child').val()) || 0;
        let infants = parseInt($('#apt-qty-infant').val()) || 0;

        $(this).prop('disabled', true).text('Booking...');

        $.ajax({
            url: apt_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_book_tickets',
                nonce: apt_ajax.nonce,
                date: selectedDate,
                slot: selectedSlot,
                adults: adults,
                children: children,
                infants: infants
            },
            success: function(response) {
                if (response.success) {
                    $('#apt-step-2').html('<div class="apt-success"><h3>Success!</h3><p>Tickets added to cart. Redirecting to checkout...</p></div>');
                    setTimeout(function() {
                        window.location.href = response.data.checkout_url;
                    }, 1500);
                } else {
                    $('#apt-btn-book').prop('disabled', false).text('Book Tickets');
                    $('#apt-availability-message').html('<span class="apt-error">' + response.data + '</span>');
                    checkAvailability(); // Refresh availability
                }
            },
            error: function() {
                $('#apt-btn-book').prop('disabled', false).text('Book Tickets');
                $('#apt-availability-message').html('<span class="apt-error">Error booking tickets. Please try again.</span>');
            }
        });
    });
});
