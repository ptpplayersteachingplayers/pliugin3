/**
 * PTP Viral Enhancements JS v116
 * Handles share interactions, review flow, and tracking
 */

(function() {
    'use strict';
    
    // Ensure config is available
    var config = window.ptpViralEnhance || {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
        siteUrl: window.location.origin,
        referralCode: '',
        referralLink: window.location.origin
    };
    
    // ==========================================
    // UTILITY FUNCTIONS
    // ==========================================
    
    /**
     * Track share action
     */
    window.ptpViralTrack = function(platform, context) {
        // Send tracking to server
        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ptp_track_share',
                nonce: config.nonce,
                platform: platform,
                context: context || 'general',
                referral_code: config.referralCode
            })
        }).catch(function(err) {
            console.log('Share tracking error:', err);
        });
        
        // Log for debugging
        console.log('[PTP Viral] Share tracked:', platform, context);
        
        return true;
    };
    
    /**
     * Copy link to clipboard
     */
    window.ptpViralCopyLink = function(link, buttonEl) {
        // Track the copy
        ptpViralTrack('copy', 'link_copy');
        
        // Copy to clipboard
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function() {
                showCopiedFeedback(buttonEl);
            }).catch(function() {
                fallbackCopy(link, buttonEl);
            });
        } else {
            fallbackCopy(link, buttonEl);
        }
    };
    
    function fallbackCopy(text, buttonEl) {
        var input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        
        try {
            document.execCommand('copy');
            showCopiedFeedback(buttonEl);
        } catch (err) {
            console.error('Copy failed:', err);
            alert('Link: ' + text);
        }
        
        document.body.removeChild(input);
    }
    
    function showCopiedFeedback(buttonEl) {
        if (!buttonEl) return;
        
        var originalHTML = buttonEl.innerHTML;
        var originalClass = buttonEl.className;
        
        buttonEl.innerHTML = '<span class="ptp-viral-btn-icon">✓</span><span>Copied!</span>';
        buttonEl.classList.add('copied');
        
        setTimeout(function() {
            buttonEl.innerHTML = originalHTML;
            buttonEl.className = originalClass;
        }, 2000);
    }
    
    // ==========================================
    // REVIEW FLOW
    // ==========================================
    
    var selectedRatings = {};
    
    /**
     * Select rating for a session
     */
    window.ptpSelectRating = function(bookingId, rating) {
        selectedRatings[bookingId] = rating;
        
        // Update star display
        var starsContainer = document.getElementById('stars-' + bookingId);
        if (starsContainer) {
            var stars = starsContainer.querySelectorAll('.ptp-star');
            stars.forEach(function(star, index) {
                if (index < rating) {
                    star.textContent = '★';
                    star.classList.add('selected');
                } else {
                    star.textContent = '☆';
                    star.classList.remove('selected');
                }
            });
        }
        
        // Show review form
        var form = document.getElementById('review-form-' + bookingId);
        if (form) {
            form.style.display = 'block';
        }
        
        // Add hover effects
        addStarHoverEffects(bookingId);
    };
    
    function addStarHoverEffects(bookingId) {
        var starsContainer = document.getElementById('stars-' + bookingId);
        if (!starsContainer) return;
        
        var stars = starsContainer.querySelectorAll('.ptp-star');
        
        stars.forEach(function(star, index) {
            star.addEventListener('mouseenter', function() {
                stars.forEach(function(s, i) {
                    if (i <= index) {
                        s.classList.add('hovered');
                        s.textContent = '★';
                    }
                });
            });
            
            star.addEventListener('mouseleave', function() {
                var currentRating = selectedRatings[bookingId] || 0;
                stars.forEach(function(s, i) {
                    s.classList.remove('hovered');
                    if (i < currentRating) {
                        s.textContent = '★';
                    } else {
                        s.textContent = '☆';
                    }
                });
            });
        });
    }
    
    /**
     * Submit review and show share modal
     */
    window.ptpSubmitReview = function(bookingId, trainerName, trainerSlug, referralLink) {
        var rating = selectedRatings[bookingId];
        if (!rating) {
            alert('Please select a rating');
            return;
        }
        
        var reviewText = '';
        var textArea = document.getElementById('review-text-' + bookingId);
        if (textArea) {
            reviewText = textArea.value;
        }
        
        // Disable submit button
        var submitBtn = document.querySelector('#review-form-' + bookingId + ' .ptp-review-submit-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }
        
        // Submit review
        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ptp_submit_review_with_share',
                nonce: config.nonce,
                booking_id: bookingId,
                rating: rating,
                review_text: reviewText
            })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // Hide the review card
                var card = document.querySelector('.ptp-review-card[data-booking="' + bookingId + '"]');
                if (card) {
                    card.style.display = 'none';
                }
                
                // Show share modal
                showReviewShareModal(trainerName, trainerSlug, rating, referralLink);
            } else {
                alert(data.data?.message || 'Error submitting review');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit & Share';
                }
            }
        })
        .catch(function(err) {
            console.error('Review submit error:', err);
            alert('Error submitting review. Please try again.');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit & Share';
            }
        });
    };
    
    function showReviewShareModal(trainerName, trainerSlug, rating, referralLink) {
        var modal = document.getElementById('review-share-modal');
        if (!modal) {
            // Create modal if it doesn't exist
            modal = createReviewShareModal();
        }
        
        // Populate share buttons
        var buttonsContainer = document.getElementById('review-share-buttons');
        if (buttonsContainer) {
            var trainerFirst = trainerName.split(' ')[0];
            var shareText = 'I just gave ' + trainerFirst + ' a ' + rating + '-star review on PTP Soccer! Check them out: ' + referralLink;
            var encodedText = encodeURIComponent(shareText);
            var encodedLink = encodeURIComponent(referralLink);
            
            buttonsContainer.innerHTML = [
                '<a href="sms:?body=' + encodedText + '" onclick="ptpViralTrack(\'sms\', \'review_share\')">💬 Text</a>',
                '<a href="https://wa.me/?text=' + encodedText + '" target="_blank" onclick="ptpViralTrack(\'whatsapp\', \'review_share\')">📱 WhatsApp</a>',
                '<a href="mailto:?subject=' + encodeURIComponent('Great soccer trainer!') + '&body=' + encodedText + '" onclick="ptpViralTrack(\'email\', \'review_share\')">✉️ Email</a>',
                '<button type="button" onclick="ptpViralCopyLink(\'' + referralLink + '\', this)">🔗 Copy</button>'
            ].join('');
        }
        
        modal.style.display = 'flex';
    }
    
    function createReviewShareModal() {
        var modal = document.createElement('div');
        modal.className = 'ptp-review-share-modal';
        modal.id = 'review-share-modal';
        modal.style.display = 'none';
        
        modal.innerHTML = [
            '<div class="ptp-review-share-modal-content">',
            '  <button type="button" class="ptp-review-share-close" onclick="ptpCloseShareModal()">×</button>',
            '  <div class="ptp-review-share-success">',
            '    <span class="ptp-review-share-check">✓</span>',
            '    <h3>Review Submitted!</h3>',
            '    <p>Thanks for helping other families find great trainers.</p>',
            '  </div>',
            '  <div class="ptp-review-share-cta">',
            '    <h4>📣 Share with Friends</h4>',
            '    <p>They get 20% off • You get $25 credit</p>',
            '    <div class="ptp-review-share-buttons" id="review-share-buttons"></div>',
            '  </div>',
            '</div>'
        ].join('');
        
        document.body.appendChild(modal);
        
        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                ptpCloseShareModal();
            }
        });
        
        return modal;
    }
    
    window.ptpCloseShareModal = function() {
        var modal = document.getElementById('review-share-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    };
    
    // ==========================================
    // TRAINER PROFILE SHARE
    // ==========================================
    
    var trainerShareOpen = false;
    
    window.ptpToggleTrainerShare = function() {
        var dropdown = document.getElementById('trainer-share-dropdown');
        if (!dropdown) return;
        
        if (trainerShareOpen) {
            dropdown.style.display = 'none';
            trainerShareOpen = false;
        } else {
            dropdown.style.display = 'block';
            trainerShareOpen = true;
        }
    };
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.ptp-trainer-share') && trainerShareOpen) {
            var dropdown = document.getElementById('trainer-share-dropdown');
            if (dropdown) {
                dropdown.style.display = 'none';
                trainerShareOpen = false;
            }
        }
    });
    
    // ==========================================
    // NATIVE SHARE API (Mobile)
    // ==========================================
    
    window.ptpNativeShare = function(title, text, url) {
        if (navigator.share) {
            navigator.share({
                title: title,
                text: text,
                url: url
            }).then(function() {
                ptpViralTrack('native', 'native_share');
            }).catch(function(err) {
                console.log('Native share cancelled or failed:', err);
            });
            return true;
        }
        return false;
    };
    
    // ==========================================
    // INITIALIZATION
    // ==========================================
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[PTP Viral] Enhancements loaded');
        
        // Initialize star hover effects for any existing review cards
        var reviewCards = document.querySelectorAll('.ptp-review-card');
        reviewCards.forEach(function(card) {
            var bookingId = card.dataset.booking;
            if (bookingId) {
                addStarHoverEffects(bookingId);
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                ptpCloseShareModal();
            }
        });
    });
    
})();
