/**
 * ShopSphere — Main JavaScript
 * Multi-Vendor E-Commerce Marketplace
 */

$(document).ready(function() {
    'use strict';

    // ===== CSRF Token Helper =====
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || 
                      $('input[name="csrf_token"]').val();

    function getCsrfToken() {
        return $('input[name="csrf_token"]').val() || csrfToken || '';
    }

    // ===== AJAX Setup =====
    $.ajaxSetup({
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    });

    // ===== Sidebar Toggle (Dashboard) =====
    $(document).on('click', '.ss-sidebar-toggle', function() {
        $('.ss-sidebar').toggleClass('show');
        $('.ss-sidebar-overlay').toggleClass('show');
    });
    $(document).on('click', '.ss-sidebar-overlay', function() {
        $('.ss-sidebar').removeClass('show');
        $(this).removeClass('show');
    });

    // ===== Mobile Search Toggle =====
    $(document).on('click', '.ss-search-toggle', function() {
        $('.ss-search-form').toggleClass('d-none d-lg-block');
    });

    // ===== Quantity Controls =====
    $(document).on('click', '.ss-qty-minus', function() {
        var input = $(this).siblings('input[type="number"], input.ss-qty-input');
        var min = parseInt(input.attr('min')) || 1;
        var val = parseInt(input.val()) || min;
        if (val > min) {
            input.val(val - 1).trigger('change');
        }
    });
    $(document).on('click', '.ss-qty-plus', function() {
        var input = $(this).siblings('input[type="number"], input.ss-qty-input');
        var max = parseInt(input.attr('max')) || 999;
        var val = parseInt(input.val()) || 1;
        if (val < max) {
            input.val(val + 1).trigger('change');
        }
    });

    // ===== Add to Cart =====
    $(document).on('click', '.ss-add-to-cart', function(e) {
        e.preventDefault();
        var btn = $(this);
        var productId = btn.data('product-id');
        var variantId = btn.data('variant-id') || '';
        var quantity = btn.closest('.ss-product-actions-form, .ss-product-info, .ss-product-card')
                        .find('.ss-qty-input').val() || 1;

        btn.prop('disabled', true).html('<span class="ss-spinner"></span> Adding...');

        $.ajax({
            url: APP_URL + '/api/cart.php',
            method: 'POST',
            data: {
                action: 'add',
                product_id: productId,
                variant_id: variantId,
                quantity: quantity,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', response.message || 'Added to cart!');
                    updateCartCount(response.cart_count);
                } else {
                    showToast('error', response.message || 'Failed to add to cart.');
                }
            },
            error: function() {
                showToast('error', 'An error occurred. Please try again.');
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="bi bi-cart-plus"></i> Add to Cart');
            }
        });
    });

    // ===== Update Cart Item =====
    $(document).on('change', '.ss-cart-qty', function() {
        var input = $(this);
        var itemId = input.data('item-id');
        var quantity = parseInt(input.val());
        var row = input.closest('tr, .ss-cart-item');

        if (quantity < 1) {
            input.val(1);
            quantity = 1;
        }

        $.ajax({
            url: APP_URL + '/api/cart.php',
            method: 'POST',
            data: {
                action: 'update',
                item_id: itemId,
                quantity: quantity,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (response.item_total) {
                        row.find('.ss-item-total').text(response.item_total);
                    }
                    if (response.cart_html) {
                        $('.ss-cart-summary').html(response.cart_html);
                    }
                    updateCartCount(response.cart_count);
                    updateCartSummary(response);
                } else {
                    showToast('error', response.message || 'Failed to update cart.');
                    input.val(input.data('original-qty'));
                }
            },
            error: function() {
                showToast('error', 'An error occurred.');
            }
        });
    });

    // ===== Remove Cart Item =====
    $(document).on('click', '.ss-cart-remove', function(e) {
        e.preventDefault();
        var btn = $(this);
        var itemId = btn.data('item-id');
        var row = btn.closest('tr, .ss-cart-item');

        if (!confirm('Remove this item from cart?')) return;

        $.ajax({
            url: APP_URL + '/api/cart.php',
            method: 'POST',
            data: {
                action: 'remove',
                item_id: itemId,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() {
                        $(this).remove();
                        if ($('.ss-cart-item, .ss-cart-table tbody tr').length === 0) {
                            location.reload();
                        }
                    });
                    updateCartCount(response.cart_count);
                    updateCartSummary(response);
                    showToast('success', 'Item removed from cart.');
                }
            }
        });
    });

    // ===== Apply Coupon =====
    $(document).on('submit', '.ss-coupon-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var code = form.find('input[name="coupon_code"]').val().trim();
        var btn = form.find('button[type="submit"]');

        if (!code) {
            showToast('error', 'Please enter a coupon code.');
            return;
        }

        btn.prop('disabled', true).html('<span class="ss-spinner"></span>');

        $.ajax({
            url: APP_URL + '/api/cart.php',
            method: 'POST',
            data: {
                action: 'apply_coupon',
                coupon_code: code,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', response.message || 'Coupon applied!');
                    if (response.redirect) {
                        location.reload();
                    }
                    updateCartSummary(response);
                } else {
                    showToast('error', response.message || 'Invalid coupon code.');
                }
            },
            error: function() {
                showToast('error', 'An error occurred.');
            },
            complete: function() {
                btn.prop('disabled', false).html('Apply');
            }
        });
    });

    // ===== Wishlist Toggle =====
    $(document).on('click', '.ss-wishlist-toggle', function(e) {
        e.preventDefault();
        var btn = $(this);
        var productId = btn.data('product-id');

        $.ajax({
            url: APP_URL + '/api/wishlist.php',
            method: 'POST',
            data: {
                action: 'toggle',
                product_id: productId,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (response.wishlisted) {
                        btn.addClass('wishlisted');
                        btn.find('.bi').removeClass('bi-heart').addClass('bi-heart-fill');
                        showToast('success', 'Added to wishlist!');
                    } else {
                        btn.removeClass('wishlisted');
                        btn.find('.bi').removeClass('bi-heart-fill').addClass('bi-heart');
                        showToast('success', 'Removed from wishlist.');
                    }
                    if (response.wishlist_count !== undefined) {
                        $('.ss-wishlist-count').text(response.wishlist_count);
                    }
                } else {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        showToast('error', response.message || 'Failed.');
                    }
                }
            }
        });
    });

    // ===== Product Image Gallery =====
    $(document).on('click', '.ss-product-thumbnail', function() {
        var img = $(this).data('image');
        var mainImg = $('.ss-product-main-image img');
        mainImg.attr('src', img);
        $('.ss-product-thumbnail').removeClass('active');
        $(this).addClass('active');
    });

    // ===== Notification Mark as Read =====
    $(document).on('click', '.ss-mark-read', function(e) {
        e.preventDefault();
        var btn = $(this);
        var notifId = btn.data('notification-id');

        $.ajax({
            url: APP_URL + '/api/notifications.php',
            method: 'POST',
            data: {
                action: 'mark_read',
                notification_id: notifId,
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    btn.closest('.ss-notification-item').removeClass('unread');
                    updateNotificationCount(response.unread_count);
                }
            }
        });
    });

    // ===== Mark All Notifications Read =====
    $(document).on('click', '.ss-mark-all-read', function(e) {
        e.preventDefault();

        $.ajax({
            url: APP_URL + '/api/notifications.php',
            method: 'POST',
            data: {
                action: 'mark_all_read',
                csrf_token: getCsrfToken()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.ss-notification-item').removeClass('unread');
                    updateNotificationCount(0);
                }
            }
        });
    });

    // ===== Delete Confirmation =====
    $(document).on('click', '[data-confirm]', function(e) {
        var message = $(this).data('confirm') || 'Are you sure?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // ===== Form Submission Prevention (Double Submit) =====
    $('form.ss-prevent-double-submit').on('submit', function() {
        var form = $(this);
        var btn = form.find('button[type="submit"]');
        if (form.data('submitted')) {
            return false;
        }
        form.data('submitted', true);
        btn.prop('disabled', true);
        setTimeout(function() {
            form.data('submitted', false);
            btn.prop('disabled', false);
        }, 5000);
    });

    // ===== Image Lazy Loading Fallback =====
    $('img[data-src]').each(function() {
        var img = $(this);
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        img.attr('src', img.data('src'));
                        observer.unobserve(entry.target);
                    }
                });
            });
            observer.observe(this);
        } else {
            img.attr('src', img.data('src'));
        }
    });

    // ===== Image Error Fallback =====
    $(document).on('error', 'img', function() {
        var placeholder = $(this).data('placeholder') || APP_URL + '/assets/images/misc/placeholder.svg';
        if ($(this).attr('src') !== placeholder) {
            $(this).attr('src', placeholder);
        }
    });

    // ===== Search Autocomplete =====
    var searchTimer;
    $(document).on('input', '.ss-search-input', function() {
        var input = $(this);
        var query = input.val().trim();
        var dropdown = input.siblings('.ss-search-dropdown');

        clearTimeout(searchTimer);

        if (query.length < 2) {
            dropdown.hide();
            return;
        }

        searchTimer = setTimeout(function() {
            $.ajax({
                url: APP_URL + '/api/search.php',
                method: 'GET',
                data: { q: query, limit: 6 },
                dataType: 'json',
                success: function(response) {
                    var items = (response && response.results) ? response.results : (Array.isArray(response) ? response : []);
                    if (items && items.length > 0) {
                        var html = '';
                        items.forEach(function(item) {
                            html += '<a href="' + APP_URL + '/product.php?slug=' + 
                                    escapeHtml(item.slug) + '" class="ss-search-result-item">';
                            html += '<img src="' + escapeHtml(item.image || APP_URL + '/assets/images/misc/placeholder.svg') + 
                                    '" alt="' + escapeHtml(item.name) + '">';
                            html += '<div><strong>' + escapeHtml(item.name) + '</strong>';
                            html += '<span>' + escapeHtml(item.price) + '</span></div>';
                            html += '</a>';
                        });
                        html += '<a href="' + APP_URL + '/search.php?q=' + encodeURIComponent(query) + 
                                '" class="ss-search-view-all">View all results</a>';
                        dropdown.html(html).show();
                    } else {
                        dropdown.html('<div class="ss-search-no-results">No products found</div>').show();
                    }
                }
            });
        }, 300);
    });

    // Close search dropdown on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.ss-search-form').length) {
            $('.ss-search-dropdown').hide();
        }
    });

    // ===== Variant Selection on Product Page =====
    $(document).on('click', '.ss-variant-btn:not(.disabled)', function() {
        var btn = $(this);
        var group = btn.closest('.ss-variant-group');
        group.find('.ss-variant-btn').removeClass('active');
        btn.addClass('active');

        // Update hidden input
        var variantId = btn.data('variant-id');
        $('input[name="variant_id"]').val(variantId);

        // Update price if variant has different price
        var priceAdj = parseFloat(btn.data('price-adjustment')) || 0;
        var basePrice = parseFloat($('.ss-base-price').data('price')) || 0;
        if (priceAdj !== 0) {
            var newPrice = basePrice + priceAdj;
            $('.current-price').text(formatPrice(newPrice));
        }

        // Update stock
        var stock = parseInt(btn.data('stock'));
        if (!isNaN(stock)) {
            updateStockDisplay(stock);
        }
    });

    // ===== Address Selection =====
    $(document).on('change', 'input[name="address_id"]', function() {
        var addressId = $(this).val();
        if (addressId === 'new') {
            $('.ss-new-address-form').slideDown();
        } else {
            $('.ss-new-address-form').slideUp();
        }
    });

    // ===== Payment Method Selection =====
    $(document).on('click', '.ss-payment-method', function() {
        var radio = $(this).find('input[type="radio"]');
        radio.prop('checked', true);
        $('.ss-payment-method').removeClass('selected');
        $(this).addClass('selected');

        // Show/hide payment-specific fields
        var method = radio.val();
        $('.ss-payment-fields').hide();
        $('#payment-fields-' + method).show();
    });

    // ===== Review Form Rating Stars =====
    $(document).on('click', '.ss-rating-input .bi', function() {
        var rating = $(this).data('rating');
        var container = $(this).closest('.ss-rating-input');
        container.find('.bi').each(function() {
            var starRating = $(this).data('rating');
            if (starRating <= rating) {
                $(this).removeClass('bi-star').addClass('bi-star-fill');
            } else {
                $(this).removeClass('bi-star-fill').addClass('bi-star');
            }
        });
        container.find('input[name="rating"]').val(rating);
    });

    // ===== Deals Countdown Timer =====
    function updateCountdowns() {
        $('.ss-countdown').each(function() {
            var endTime = new Date($(this).data('end')).getTime();
            var now = new Date().getTime();
            var diff = endTime - now;

            if (diff <= 0) {
                $(this).html('<span class="text-ss-danger">Expired</span>');
                return;
            }

            var days = Math.floor(diff / (1000 * 60 * 60 * 24));
            var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((diff % (1000 * 60)) / 1000);

            $(this).find('.days').text(pad(days));
            $(this).find('.hours').text(pad(hours));
            $(this).find('.minutes').text(pad(minutes));
            $(this).find('.seconds').text(pad(seconds));
        });
    }
    if ($('.ss-countdown').length) {
        updateCountdowns();
        setInterval(updateCountdowns, 1000);
    }

    // ===== File Upload Preview =====
    $(document).on('change', '.ss-file-upload', function() {
        var input = this;
        var preview = $(this).closest('.ss-upload-group').find('.ss-upload-preview');

        if (input.files && input.files[0]) {
            var file = input.files[0];
            var maxSize = parseInt($(this).data('max-size')) || 5242880; // 5MB

            // Validate file size
            if (file.size > maxSize) {
                showToast('error', 'File size exceeds maximum allowed size.');
                $(this).val('');
                return;
            }

            // Validate file type
            var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                showToast('error', 'Only JPG, PNG, GIF, and WebP images are allowed.');
                $(this).val('');
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                preview.html('<img src="' + e.target.result + '" alt="Preview" style="max-width:200px;max-height:200px;border-radius:8px;">');
            };
            reader.readAsDataURL(file);
        }
    });

    // ===== Multiple Image Upload Preview =====
    $(document).on('change', '.ss-multi-upload', function() {
        var input = this;
        var preview = $(this).closest('.ss-upload-group').find('.ss-multi-preview');
        preview.empty();

        if (input.files) {
            Array.from(input.files).forEach(function(file) {
                if (file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        preview.append(
                            '<div class="ss-preview-thumb" style="display:inline-block;margin:4px;">' +
                            '<img src="' + e.target.result + '" alt="Preview" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">' +
                            '</div>'
                        );
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    });

    // ===== Sortable Table Headers =====
    $(document).on('click', '.ss-sortable', function() {
        var column = $(this).data('sort');
        var currentDir = $(this).data('direction') || 'asc';
        var newDir = currentDir === 'asc' ? 'desc' : 'asc';

        var url = new URL(window.location.href);
        url.searchParams.set('sort', column);
        url.searchParams.set('direction', newDir);
        url.searchParams.set('page', '1');

        window.location.href = url.toString();
    });

    // ===== Print Button =====
    $(document).on('click', '.ss-print-btn', function() {
        window.print();
    });

    // ===== Dismiss Alerts =====
    $(document).on('click', '.ss-alert-dismiss', function() {
        $(this).closest('.ss-alert').fadeOut(200, function() {
            $(this).remove();
        });
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.ss-alert[data-auto-dismiss]').fadeOut(300, function() {
            $(this).remove();
        });
    }, 5000);

    // ===== Select All Checkbox =====
    $(document).on('change', '.ss-select-all', function() {
        var checked = $(this).prop('checked');
        var table = $(this).closest('table');
        table.find('.ss-select-item').prop('checked', checked);
        updateBulkActions();
    });
    $(document).on('change', '.ss-select-item', function() {
        updateBulkActions();
    });

    function updateBulkActions() {
        var count = $('.ss-select-item:checked').length;
        if (count > 0) {
            $('.ss-bulk-actions').show();
            $('.ss-bulk-count').text(count);
        } else {
            $('.ss-bulk-actions').hide();
        }
    }

    // ===== Helper Functions =====
    function updateCartCount(count) {
        var badge = $('.ss-cart-count');
        if (count > 0) {
            badge.text(count).show();
        } else {
            badge.text('0').hide();
        }
    }

    function updateCartSummary(data) {
        if (data.subtotal !== undefined) {
            $('.ss-summary-subtotal').text(data.subtotal);
        }
        if (data.discount !== undefined) {
            $('.ss-summary-discount').text('-' + data.discount);
            if (parseFloat(data.discount_raw) > 0) {
                $('.ss-summary-discount-row').show();
            } else {
                $('.ss-summary-discount-row').hide();
            }
        }
        if (data.shipping !== undefined) {
            $('.ss-summary-shipping').text(data.shipping);
        }
        if (data.total !== undefined) {
            $('.ss-summary-total').text(data.total);
        }
    }

    function updateNotificationCount(count) {
        var badge = $('.ss-notification-count');
        if (count > 0) {
            badge.text(count).show();
        } else {
            badge.text('0').hide();
        }
    }

    function updateStockDisplay(stock) {
        var el = $('.ss-stock-status');
        el.removeClass('in-stock low-stock out-of-stock');
        if (stock === 0) {
            el.addClass('out-of-stock').html('<i class="bi bi-x-circle"></i> Out of Stock');
            $('.ss-add-to-cart').prop('disabled', true);
        } else if (stock <= 5) {
            el.addClass('low-stock').html('<i class="bi bi-exclamation-circle"></i> Only ' + stock + ' left');
            $('.ss-add-to-cart').prop('disabled', false);
        } else {
            el.addClass('in-stock').html('<i class="bi bi-check-circle"></i> In Stock');
            $('.ss-add-to-cart').prop('disabled', false);
        }
    }

    function showToast(type, message) {
        var icon = type === 'success' ? 'bi-check-circle-fill' : 
                   type === 'error' ? 'bi-exclamation-circle-fill' : 
                   type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill';
        var bgClass = type === 'success' ? 'ss-alert-success' : 
                      type === 'error' ? 'ss-alert-danger' : 
                      type === 'warning' ? 'ss-alert-warning' : 'ss-alert-info';

        var toast = $('<div class="ss-toast ss-alert ' + bgClass + '" data-auto-dismiss>' +
            '<i class="bi ' + icon + '"></i>' +
            '<span>' + escapeHtml(message) + '</span>' +
            '<button type="button" class="btn-close btn-close-sm ss-alert-dismiss" style="margin-left:auto;"></button>' +
            '</div>');

        // Create toast container if not exists
        if (!$('.ss-toast-container').length) {
            $('body').append('<div class="ss-toast-container" style="position:fixed;top:20px;right:20px;z-index:10000;max-width:400px;"></div>');
        }

        $('.ss-toast-container').prepend(toast);

        setTimeout(function() {
            toast.fadeOut(300, function() { $(this).remove(); });
        }, 4000);
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatPrice(amount) {
        var symbol = typeof CURRENCY_SYMBOL !== 'undefined' ? CURRENCY_SYMBOL : 'Rs.';
        return symbol + ' ' + parseFloat(amount).toLocaleString('en-PK', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }

    function pad(num) {
        return num < 10 ? '0' + num : num;
    }

    // Export to global scope for inline usage where needed
    window.ShopSphere = {
        showToast: showToast,
        escapeHtml: escapeHtml,
        formatPrice: formatPrice,
        getCsrfToken: getCsrfToken,
        updateCartCount: updateCartCount
    };
});
