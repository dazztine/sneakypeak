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

  function adjustCardBadgePosition(card, badgeEl) {
    if (!card || !badgeEl || badgeEl.classList.contains('sneakypeak-badge-single-wrap')) {
      return;
    }

    var img = card.querySelector('img, picture');
    if (!img) {
      badgeEl.style.removeProperty('left');
      badgeEl.style.removeProperty('right');
      return;
    }

    var cardRect = card.getBoundingClientRect();
    var imgRect = img.getBoundingClientRect();

    // If the card is more than about 1.25 times wider than the image block, position from image edge
    if (imgRect.width > 0 && cardRect.width > 1.25 * imgRect.width) {
      var isRight = badgeEl.classList.contains('sneakypeak-corner-top-right') ||
                    badgeEl.classList.contains('sneakypeak-corner-bottom-right') ||
                    (!badgeEl.classList.contains('sneakypeak-corner-top-left') && !badgeEl.classList.contains('sneakypeak-corner-bottom-left'));

      if (isRight) {
        var rightOffset = Math.round(cardRect.right - imgRect.right);
        badgeEl.style.setProperty('right', rightOffset + 'px', 'important');
        badgeEl.style.setProperty('left', 'auto', 'important');
      } else {
        var leftOffset = Math.round(imgRect.left - cardRect.left);
        badgeEl.style.setProperty('left', leftOffset + 'px', 'important');
        badgeEl.style.setProperty('right', 'auto', 'important');
      }
    } else {
      badgeEl.style.removeProperty('left');
      badgeEl.style.removeProperty('right');
    }
  }

  function adjustAllShopCardBadges() {
    var badges = document.querySelectorAll('.sneakypeak-badge-wrap:not(.sneakypeak-badge-single-wrap)');
    badges.forEach(function (badgeEl) {
      var card = badgeEl.parentElement;
      if (card) {
        adjustCardBadgePosition(card, badgeEl);
      }
    });
  }

  function applyBadges() {
    var cardSelector = 'li.product, .product, .wc-block-grid__product, .wc-block-product-template__item, .wp-block-post, .type-product, .product-card, .product-item, .product-small, .product-wrap, .entry-product, article.product, article.type-product, [data-product-id]';
    var excludedSelector = '.widget_shopping_cart, .woocommerce-mini-cart, .cart_item, .woocommerce-cart-form, .woocommerce-checkout, form.checkout, .checkout, .summary.entry-summary';

    // 1. Process cards marked with .sneakypeak-promo, .sneakypeak-teaser-wrap, or .sneakypeak-card-marker
    var anchors = document.querySelectorAll('.sneakypeak-promo, .sneakypeak-teaser-wrap, .sneakypeak-card-marker');

    anchors.forEach(function (el) {
      var card = null;
      var candidate = el.closest(cardSelector);
      if (!candidate && el.matches(cardSelector)) {
        candidate = el;
      }
      if (!candidate) {
        var loopLink = el.closest('a, .woocommerce-loop-product__link, .price');
        if (loopLink && loopLink.parentElement) {
          candidate = loopLink.parentElement.closest(cardSelector) || loopLink.parentElement;
        }
      }

      // Size and content sanity check before attaching a badge to a candidate card.
      // Reject and keep climbing no further if the candidate contains more than one SneakyPeak anchor
      // or more than one li.product, .type-product, .wc-block-product-template__item.
      if (candidate) {
        var anchorsInCandidate = candidate.querySelectorAll('.sneakypeak-promo, .sneakypeak-teaser-wrap, .sneakypeak-card-marker');
        var productsInCandidate = candidate.querySelectorAll('li.product, .type-product, .wc-block-product-template__item');
        if (anchorsInCandidate.length <= 1 && productsInCandidate.length <= 1) {
          card = candidate;
        }
        // If rejected, card remains null ("Reject and keep climbing no further")
      }

      // If no valid card is found, use the closest ancestor of the anchor that contains exactly one product image.
      if (!card) {
        var curr = el.parentElement;
        while (curr && curr !== document.body && curr !== document.documentElement) {
          var imgs = curr.querySelectorAll('img');
          if (imgs.length === 0) {
            imgs = curr.querySelectorAll('picture');
          }
          if (imgs.length === 1) {
            card = curr;
            break;
          } else if (imgs.length > 1) {
            break;
          }
          curr = curr.parentElement;
        }
      }

      if (!card) {
        return;
      }

      // Skip non-catalog contexts (cart, checkout, mini-cart, single summary)
      if (card.closest(excludedSelector) || card.matches(excludedSelector)) {
        return;
      }

      // On single product page, do not treat the primary product layout container as a catalog card
      if (document.body && (document.body.classList.contains('single-product') || document.body.classList.contains('sneakypeak-promo-single'))) {
        if (el.closest('.woocommerce-product-gallery, .summary, .entry-summary, .wp-block-woocommerce-product-gallery, .wp-block-woocommerce-product-summary')) {
          return;
        }
      }

      // Deduplicate: only one SneakyPeak badge per card
      if (card.querySelector('.sneakypeak-badge-wrap')) {
        return;
      }

      // Extract campaign ID from class list (e.g. sneakypeak-campaign-123) or data-campaign-id
      var campaignId = 0;
      var teaser = card.querySelector('.sneakypeak-teaser-wrap, .sneakypeak-card-marker');
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

      // Handle card wider than its content (e.g. single-result case)
      adjustCardBadgePosition(card, badgeEl);
    });

    // 2. Single product page badge handling (gallery, summary, or custom CSS selector)
    var isSingleProduct = !!(document.body && (
      document.body.classList.contains('sneakypeak-promo-single') ||
      document.body.classList.contains('single-product') ||
      document.body.classList.contains('product-template-default')
    )) || !!document.querySelector('.woocommerce-product-gallery, .summary.entry-summary, .wp-block-woocommerce-product-gallery, .wp-block-woocommerce-product-summary');

    if (isSingleProduct) {
      var singleCid = 0;
      if (document.body) {
        var singleMatch = document.body.className.match(/sneakypeak-campaign-(\d+)/);
        if (singleMatch && singleMatch[1]) {
          singleCid = parseInt(singleMatch[1], 10);
        }
      }
      if (!singleCid) {
        var marker = document.querySelector('.sneakypeak-card-marker[data-campaign-id], .sneakypeak-teaser-wrap[data-campaign-id]');
        if (marker && marker.getAttribute('data-campaign-id')) {
          singleCid = parseInt(marker.getAttribute('data-campaign-id'), 10);
        }
      }
      if (!singleCid) {
        var firstKey = Object.keys(config.campaigns)[0];
        singleCid = firstKey ? parseInt(firstKey, 10) : 0;
      }

      if (singleCid && config.campaigns[singleCid]) {
        var camp = config.campaigns[singleCid];
        var pos = camp.singlePosition || 'gallery';

        // Check if single badge is already rendered
        var existingSingleBadge = document.querySelector('.sneakypeak-badge-single-wrap, .sneakypeak-single-summary-wrap .sneakypeak-badge-wrap, .sneakypeak-single-fallback-wrap .sneakypeak-badge-wrap');

        if (!existingSingleBadge) {
          if (pos === 'custom' && camp.singleCustomSelector) {
            var customTarget = null;
            try {
              customTarget = document.querySelector(camp.singleCustomSelector);
            } catch (e) {
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
            } else if (!customTarget) {
              // Custom target not found; fall back to gallery
              pos = 'gallery';
            }
          }

          if (pos === 'summary') {
            var summaryTarget = document.querySelector('.sneakypeak-single-summary-wrap, .summary.entry-summary, .wp-block-woocommerce-product-summary, .product-summary');
            if (summaryTarget && !summaryTarget.querySelector('.sneakypeak-badge-wrap')) {
              var summaryBadge = createBadgeElement(singleCid, true);
              if (summaryBadge) {
                var sumWrap = document.createElement('div');
                sumWrap.className = 'sneakypeak-single-summary-wrap';
                sumWrap.style.cssText = 'position:relative; margin-bottom:12px; display:inline-block; clear:both;';
                sumWrap.appendChild(summaryBadge);
                summaryTarget.insertBefore(sumWrap, summaryTarget.firstChild);
              }
            }
          } else if (pos === 'gallery') {
            var galleryImage = document.querySelector('.woocommerce-product-gallery__image, .woocommerce-product-gallery, .wp-block-woocommerce-product-gallery');
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
  }

  // Debounced handler for window resize and load events to adjust oversized cards
  var resizeDebounceTimer = null;
  function handleResizeOrLoad() {
    if (resizeDebounceTimer) {
      clearTimeout(resizeDebounceTimer);
    }
    resizeDebounceTimer = setTimeout(function () {
      adjustAllShopCardBadges();
    }, 100);
  }

  window.addEventListener('resize', handleResizeOrLoad);
  window.addEventListener('load', handleResizeOrLoad);

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
