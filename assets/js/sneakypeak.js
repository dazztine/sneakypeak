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

  function applyTransformFallback(badgeEl, campData) {
    if (!badgeEl) {
      return;
    }
    var nudgeX = (campData && typeof campData.nudgeX !== 'undefined')
      ? campData.nudgeX
      : parseInt(badgeEl.getAttribute('data-nudge-x') || '0', 10);
    var nudgeY = (campData && typeof campData.nudgeY !== 'undefined')
      ? campData.nudgeY
      : parseInt(badgeEl.getAttribute('data-nudge-y') || '0', 10);

    if (!nudgeX && !nudgeY) {
      return;
    }

    var computed = window.getComputedStyle(badgeEl);
    // If computed transform is 'none' (overridden by theme), fall back to margins
    if (!computed.transform || computed.transform === 'none') {
      if (nudgeX) {
        badgeEl.style.marginLeft = nudgeX + 'px';
      }
      if (nudgeY) {
        badgeEl.style.marginTop = nudgeY + 'px';
      }
    } else {
      badgeEl.style.removeProperty('margin-left');
      badgeEl.style.removeProperty('margin-top');
    }
  }

  function getVisualCard(card, badgeEl) {
    if (!card) {
      return null;
    }
    var img = card.querySelector('img, picture');
    if (!img) {
      return card;
    }

    var titleOrPriceSelector = '.woocommerce-loop-product__title, .product-title, h2, h3, .price, .woocommerce-Price-amount';
    var curr = img.parentElement;
    while (curr && curr !== card && card.contains(curr)) {
      if (curr.contains(img) && curr.querySelector(titleOrPriceSelector)) {
        return curr;
      }
      curr = curr.parentElement;
    }

    return card;
  }

  function adjustCardBadgePosition(card, badgeEl) {
    if (!card || !badgeEl || badgeEl.classList.contains('sneakypeak-badge-single-wrap')) {
      return;
    }

    var campaignId = parseInt(badgeEl.getAttribute('data-campaign-id'), 10) || 0;
    var campData = config.campaigns[campaignId];
    var badgeAnchor = (campData && campData.badgeAnchor)
      ? campData.badgeAnchor
      : (badgeEl.getAttribute('data-badge-anchor') || 'auto');

    // 1. Card edge (strict): never re-anchor, badge stays flush to card edge
    if (badgeAnchor === 'card') {
      badgeEl.style.removeProperty('left');
      badgeEl.style.removeProperty('right');
      badgeEl.style.removeProperty('top');
      badgeEl.style.removeProperty('bottom');
      applyTransformFallback(badgeEl, campData);
      return;
    }

    // 2. Auto (recommended): anchor to the visual card if the matched card is stretched
    if (badgeAnchor === 'auto') {
      var visualCard = getVisualCard(card, badgeEl);
      var cardRect = card.getBoundingClientRect();
      var visualRect = visualCard ? visualCard.getBoundingClientRect() : cardRect;

      // If cardRect.width > 1.25 * visualRect.width (matched card is much wider than the visible card)
      if (visualRect.width > 0 && cardRect.width > 1.25 * visualRect.width) {
        var isRight = badgeEl.classList.contains('sneakypeak-corner-top-right') ||
                      badgeEl.classList.contains('sneakypeak-corner-bottom-right') ||
                      (!badgeEl.classList.contains('sneakypeak-corner-top-left') && !badgeEl.classList.contains('sneakypeak-corner-bottom-left'));

        var isBottom = badgeEl.classList.contains('sneakypeak-corner-bottom-right') ||
                       badgeEl.classList.contains('sneakypeak-corner-bottom-left');

        if (isRight) {
          var rightOffset = Math.round(cardRect.right - visualRect.right);
          badgeEl.style.setProperty('right', rightOffset + 'px', 'important');
          badgeEl.style.setProperty('left', 'auto', 'important');
        } else {
          var leftOffset = Math.round(visualRect.left - cardRect.left);
          badgeEl.style.setProperty('left', leftOffset + 'px', 'important');
          badgeEl.style.setProperty('right', 'auto', 'important');
        }

        if (isBottom) {
          var bottomOffset = Math.round(cardRect.bottom - visualRect.bottom);
          badgeEl.style.setProperty('bottom', bottomOffset + 'px', 'important');
          badgeEl.style.setProperty('top', 'auto', 'important');
        } else {
          var topOffset = Math.round(visualRect.top - cardRect.top);
          badgeEl.style.setProperty('top', topOffset + 'px', 'important');
          badgeEl.style.setProperty('bottom', 'auto', 'important');
        }
      } else {
        badgeEl.style.removeProperty('left');
        badgeEl.style.removeProperty('right');
        badgeEl.style.removeProperty('top');
        badgeEl.style.removeProperty('bottom');
      }

      applyTransformFallback(badgeEl, campData);
      return;
    }

    // 3. Image edge: anchor to the main img / picture element
    if (badgeAnchor === 'image') {
      var img = card.querySelector('img, picture');
      if (!img) {
        badgeEl.style.removeProperty('left');
        badgeEl.style.removeProperty('right');
        badgeEl.style.removeProperty('top');
        badgeEl.style.removeProperty('bottom');
        applyTransformFallback(badgeEl, campData);
        return;
      }

      var cardRectImg = card.getBoundingClientRect();
      var imgRect = img.getBoundingClientRect();

      if (imgRect.width > 0 && cardRectImg.width > 1.75 * imgRect.width) {
        var leftGap = imgRect.left - cardRectImg.left;
        var rightGap = cardRectImg.right - imgRect.right;
        var isLeftAligned = leftGap <= Math.max(60, rightGap * 0.5);
        var isCentred = Math.abs(leftGap - rightGap) <= (cardRectImg.width * 0.25);

        if (isLeftAligned || isCentred) {
          var isRightImg = badgeEl.classList.contains('sneakypeak-corner-top-right') ||
                           badgeEl.classList.contains('sneakypeak-corner-bottom-right') ||
                           (!badgeEl.classList.contains('sneakypeak-corner-top-left') && !badgeEl.classList.contains('sneakypeak-corner-bottom-left'));

          if (isRightImg) {
            var rightOffsetImg = Math.round(cardRectImg.right - imgRect.right);
            badgeEl.style.setProperty('right', rightOffsetImg + 'px', 'important');
            badgeEl.style.setProperty('left', 'auto', 'important');
          } else {
            var leftOffsetImg = Math.round(imgRect.left - cardRectImg.left);
            badgeEl.style.setProperty('left', leftOffsetImg + 'px', 'important');
            badgeEl.style.setProperty('right', 'auto', 'important');
          }
        } else {
          badgeEl.style.removeProperty('left');
          badgeEl.style.removeProperty('right');
        }
      } else {
        badgeEl.style.removeProperty('left');
        badgeEl.style.removeProperty('right');
      }

      applyTransformFallback(badgeEl, campData);
      return;
    }

    applyTransformFallback(badgeEl, campData);
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
      // Reject and keep climbing no further if candidate contains more than one product card element.
      if (candidate) {
        var productsInCandidate = candidate.querySelectorAll('li.product, .type-product, .wc-block-product-template__item, article.product, article.type-product, .product-card');
        if (productsInCandidate.length <= 1) {
          card = candidate;
        }
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

      // Extract all campaign IDs that apply to this card
      var campaignIds = [];

      // 1. From markers and teasers inside the card
      var cardMarkers = card.querySelectorAll('.sneakypeak-teaser-wrap[data-campaign-id], .sneakypeak-card-marker[data-campaign-id]');
      cardMarkers.forEach(function (marker) {
        var cid = parseInt(marker.getAttribute('data-campaign-id'), 10);
        if (cid && config.campaigns[cid] && campaignIds.indexOf(cid) === -1) {
          campaignIds.push(cid);
        }
      });

      // 2. From the anchor element itself if it has data-campaign-id
      if (el.getAttribute && el.getAttribute('data-campaign-id')) {
        var elCid = parseInt(el.getAttribute('data-campaign-id'), 10);
        if (elCid && config.campaigns[elCid] && campaignIds.indexOf(elCid) === -1) {
          campaignIds.push(elCid);
        }
      }

      // 3. From card CSS classes (e.g. sneakypeak-campaign-123)
      var classRegex = /sneakypeak-campaign-(\d+)/g;
      var classMatch;
      while ((classMatch = classRegex.exec(card.className)) !== null) {
        var cIdFromClass = parseInt(classMatch[1], 10);
        if (cIdFromClass && config.campaigns[cIdFromClass] && campaignIds.indexOf(cIdFromClass) === -1) {
          campaignIds.push(cIdFromClass);
        }
      }

      // 4. Fallback: pick the first active campaign in config if no ID found
      if (campaignIds.length === 0) {
        var keys = Object.keys(config.campaigns);
        if (keys.length > 0) {
          var firstCid = parseInt(keys[0], 10);
          if (firstCid && campaignIds.indexOf(firstCid) === -1) {
            campaignIds.push(firstCid);
          }
        }
      }

      // Render and position a badge for each matching campaign on this card
      campaignIds.forEach(function (campaignId) {
        // Deduplicate per campaign: only one badge per campaign on this card
        if (card.querySelector('.sneakypeak-badge-wrap[data-campaign-id="' + campaignId + '"]')) {
          return;
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

        // Re-run after card image finishes loading if not already complete
        var cardImg = card.querySelector('img');
        if (cardImg && !cardImg.complete) {
          cardImg.addEventListener('load', function () {
            adjustCardBadgePosition(card, badgeEl);
          }, { once: true });
        }
      });
    });

    // 2. Single product page badge handling (gallery, summary, or custom CSS selector)
    var isSingleProduct = !!(document.body && (
      document.body.classList.contains('sneakypeak-promo-single') ||
      document.body.classList.contains('single-product') ||
      document.body.classList.contains('product-template-default')
    )) || !!document.querySelector('.woocommerce-product-gallery, .summary.entry-summary, .wp-block-woocommerce-product-gallery, .wp-block-woocommerce-product-summary');

    if (isSingleProduct) {
      var singleCids = [];
      var singleClassSources = [document.body, document.querySelector('.sneakypeak-promo-single')];
      singleClassSources.forEach(function (src) {
        if (!src) {
          return;
        }
        var singleMatchRegex = /sneakypeak-campaign-(\d+)/g;
        var sm;
        while ((sm = singleMatchRegex.exec(src.className || '')) !== null) {
          var scid = parseInt(sm[1], 10);
          if (scid && config.campaigns[scid] && singleCids.indexOf(scid) === -1) {
            singleCids.push(scid);
          }
        }
      });
      var singleMarkers = document.querySelectorAll('.sneakypeak-card-marker[data-campaign-id], .sneakypeak-teaser-wrap[data-campaign-id]');
      singleMarkers.forEach(function (marker) {
        var scid = parseInt(marker.getAttribute('data-campaign-id'), 10);
        if (scid && config.campaigns[scid] && singleCids.indexOf(scid) === -1) {
          singleCids.push(scid);
        }
      });
      if (singleCids.length === 0) {
        var firstKey = Object.keys(config.campaigns)[0];
        if (firstKey) {
          singleCids.push(parseInt(firstKey, 10));
        }
      }

      singleCids.forEach(function (singleCid) {
        var camp = config.campaigns[singleCid];
        if (!camp) {
          return;
        }
        var pos = camp.singlePosition || 'gallery';

        // Check if single badge for this campaign is already rendered
        var existingBadge = document.querySelector('.sneakypeak-badge-wrap[data-campaign-id="' + singleCid + '"]');
        if (existingBadge) {
          return;
        }

        if (pos === 'custom' && camp.singleCustomSelector) {
          var customTarget = null;
          try {
            customTarget = document.querySelector(camp.singleCustomSelector);
          } catch (e) {
            customTarget = null;
          }
          if (customTarget && !customTarget.querySelector('.sneakypeak-badge-wrap[data-campaign-id="' + singleCid + '"]')) {
            var customBadge = createBadgeElement(singleCid, true);
            if (customBadge) {
              var ctComputed = window.getComputedStyle(customTarget);
              if (ctComputed.position === 'static') {
                customTarget.style.position = 'relative';
              }
              customTarget.appendChild(customBadge);
              return;
            }
          } else if (!customTarget) {
            // Custom target not found; fall back to gallery
            pos = 'gallery';
          }
        }

        if (pos === 'summary') {
          var summaryTarget = document.querySelector('.sneakypeak-single-summary-wrap, .summary.entry-summary, .wp-block-woocommerce-product-summary, .product-summary');
          if (summaryTarget && !summaryTarget.querySelector('.sneakypeak-badge-wrap[data-campaign-id="' + singleCid + '"]')) {
            var summaryBadge = createBadgeElement(singleCid, true);
            if (summaryBadge) {
              var sumWrap = summaryTarget.closest('.sneakypeak-single-summary-wrap') || summaryTarget.querySelector('.sneakypeak-single-summary-wrap');
              if (sumWrap) {
                sumWrap.appendChild(summaryBadge);
              } else {
                var newSumWrap = document.createElement('div');
                newSumWrap.className = 'sneakypeak-single-summary-wrap';
                newSumWrap.style.cssText = 'position:relative; margin-bottom:12px; display:inline-block; clear:both;';
                newSumWrap.appendChild(summaryBadge);
                summaryTarget.insertBefore(newSumWrap, summaryTarget.firstChild);
              }
            }
          }
        } else if (pos === 'gallery') {
          var galleryImage = document.querySelector('.woocommerce-product-gallery__image') || document.querySelector('.woocommerce-product-gallery, .wp-block-woocommerce-product-gallery');
          if (galleryImage && !galleryImage.querySelector('.sneakypeak-badge-wrap[data-campaign-id="' + singleCid + '"]')) {
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
      });
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
