/**
 * SneakyPeak Front-end Vanilla JavaScript Engine
 *
 * RULE 1: Never wrap or alter product images, anchors, or image containers.
 * The badge attaches to the product CARD element only as a direct child.
 * Security: Uses DOM methods and textContent; no innerHTML with dynamic unescaped content.
 */
(function () {
  'use strict';

  if (typeof window.sneakypeakConfig === 'undefined' || !window.sneakypeakConfig.campaigns) {
    return;
  }

  var config = window.sneakypeakConfig;
  var isDebug = !!config.debug || window.location.search.indexOf('sneakypeak_debug=1') !== -1;

  function createBadgeElement(campaignId, isSingle) {
    var campData = config.campaigns[campaignId];
    if (!campData) {
      return null;
    }

    var html = (isSingle && campData.singleBadgeHtml) ? campData.singleBadgeHtml : campData.badgeHtml;
    if (!html) {
      return null;
    }

    var temp = document.createElement('div');
    temp.innerHTML = html;
    var badgeElement = temp.firstElementChild;
    if (!badgeElement) {
      return null;
    }

    if (isSingle) {
      badgeElement.classList.add('sneakypeak-badge-single-wrap');
      var inner = badgeElement.querySelector('.sneakypeak-badge');
      if (inner) {
        inner.classList.add('sneakypeak-badge-single');
      }
    }

    return badgeElement;
  }

  function applyBadges() {
    var cardSelector = 'li.product, .product, .wc-block-grid__product, .wp-block-post, .type-product, article';
    var excludedSelector = '.widget_shopping_cart, .woocommerce-mini-cart, .cart_item, .woocommerce-cart-form, .woocommerce-checkout, form.checkout, .checkout, .summary.entry-summary';

    // 1. Process cards marked with .sneakypeak-promo or containing a teaser block
    var anchors = document.querySelectorAll('.sneakypeak-promo, .sneakypeak-teaser-wrap');

    anchors.forEach(function (el) {
      var card = el.closest(cardSelector);
      if (!card) {
        if (el.matches(cardSelector)) {
          card = el;
        } else {
          return;
        }
      }

      // Skip non-catalog contexts (cart, checkout, mini-cart, single summary)
      if (card.closest(excludedSelector) || card.matches(excludedSelector)) {
        return;
      }

      // Deduplicate: only one SneakyPeak badge per card
      if (card.querySelector('.sneakypeak-badge-wrap')) {
        return;
      }

      // Extract campaign ID from class list (e.g. sneakypeak-campaign-123) or data-campaign-id
      var campaignId = 0;
      var teaser = card.querySelector('.sneakypeak-teaser-wrap');
      if (teaser && teaser.getAttribute('data-campaign-id')) {
        campaignId = parseInt(teaser.getAttribute('data-campaign-id'), 10);
      } else {
        var match = card.className.match(/sneakypeak-campaign-(\d+)/);
        if (match && match[1]) {
          campaignId = parseInt(match[1], 10);
        }
      }

      // Fallback: pick the first active campaign in config if ID not in class
      if (!campaignId || !config.campaigns[campaignId]) {
        var keys = Object.keys(config.campaigns);
        if (keys.length > 0) {
          campaignId = parseInt(keys[0], 10);
        } else {
          return;
        }
      }

      var badgeEl = createBadgeElement(campaignId, false);
      if (!badgeEl) {
        return;
      }

      // Ensure card container has relative positioning if static, without touching images or links
      var cardComputed = window.getComputedStyle(card);
      if (cardComputed.position === 'static') {
        card.style.position = 'relative';
      }

      if (isDebug && typeof console !== 'undefined' && console.log) {
        console.log('[SneakyPeak Debug] Attached badge for campaign #' + campaignId + ' to card: <' + card.tagName.toLowerCase() + '> ' + (card.className || ''));
      }

      // Append directly to the card
      card.appendChild(badgeEl);
    });

    // 2. Single product page badge handling (gallery, summary fallback, or custom CSS selector)
    if (document.body && document.body.classList.contains('sneakypeak-promo-single')) {
      var singleMatch = document.body.className.match(/sneakypeak-campaign-(\d+)/);
      var singleCid = singleMatch && singleMatch[1] ? parseInt(singleMatch[1], 10) : 0;
      if (!singleCid) {
        var firstKey = Object.keys(config.campaigns)[0];
        singleCid = firstKey ? parseInt(firstKey, 10) : 0;
      }

      if (singleCid && config.campaigns[singleCid]) {
        var camp = config.campaigns[singleCid];
        var pos = camp.singlePosition || 'gallery';

        // Custom selector placement
        var customTarget = null;
        if (pos === 'custom' && camp.singleCustomSelector) {
          try {
            customTarget = document.querySelector(camp.singleCustomSelector);
          } catch (e) {
            // Bad selector fails quietly
            customTarget = null;
          }
          if (customTarget && !customTarget.querySelector('.sneakypeak-badge-wrap')) {
            var customBadge = createBadgeElement(singleCid, true);
            if (customBadge) {
              var ctComputed = window.getComputedStyle(customTarget);
              if (ctComputed.position === 'static') {
                customTarget.style.position = 'relative';
              }
              customTarget.appendChild(customBadge);
            }
          }
        }

        // Gallery mode or fallback if custom selector target was not found
        if (pos === 'gallery' || (pos === 'custom' && !customTarget)) {
          var galleryImage = document.querySelector('.woocommerce-product-gallery__image, .woocommerce-product-gallery');
          if (galleryImage && !galleryImage.querySelector('.sneakypeak-badge-wrap')) {
            var singleBadge = createBadgeElement(singleCid, true);
            if (singleBadge) {
              var galComputed = window.getComputedStyle(galleryImage);
              if (galComputed.position === 'static') {
                galleryImage.style.position = 'relative';
              }
              galleryImage.appendChild(singleBadge);
            }
          }
        }
      }
    }
  }

  // Run on initial load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyBadges);
  } else {
    applyBadges();
  }

  // Debounced MutationObserver for dynamic AJAX filters, infinite scrolls, and quick views
  var debounceTimer = null;
  var observer = new MutationObserver(function () {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(function () {
      applyBadges();
    }, 150);
  });

  if (document.body) {
    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });
  }
})();
