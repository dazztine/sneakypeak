/**
 * SneakyPeak Admin Preview & Live Designer Script
 */
(function ($) {
  'use strict';

  $(document).ready(function () {
    var $previewCard = $('#sneakypeak-mock-card');
    if (!$previewCard.length) {
      return;
    }

    var currentPreviewPhase = 'teaser'; // 'teaser' or 'live'

    var gFontMap = {
      'jost': 'Jost:wght@400;600;700',
      'montserrat': 'Montserrat:wght@400;600;700',
      'poppins': 'Poppins:wght@400;600;700',
      'outfit': 'Outfit:wght@400;600;700',
      'inter': 'Inter:wght@400;600;700',
      'questrial': 'Questrial'
    };

    // Tab toggle: Shop card | Single product
    $('.sneakypeak-mock-tab-toggle').on('click', function (e) {
      e.preventDefault();
      $('.sneakypeak-mock-tab-toggle').removeClass('active button-primary').addClass('button-secondary');
      $(this).removeClass('button-secondary').addClass('active button-primary');

      var tab = $(this).data('tab');
      if (tab === 'single') {
        $('#sneakypeak-mock-shop-container').hide();
        $('#sneakypeak-mock-single-container').show();
      } else {
        $('#sneakypeak-mock-single-container').hide();
        $('#sneakypeak-mock-shop-container').show();
      }
    });

    function updateBadgePreview() {
      // General Badge Settings
      var corner = $('select[name="sneakypeak[badge_corner]"]').val() || 'top-right';
      var shape = $('select[name="sneakypeak[badge_shape]"]').val() || 'ribbon';
      var size = $('select[name="sneakypeak[badge_size]"]').val() || 'medium';
      var fontSize = parseInt($('input[name="sneakypeak[badge_font_size]"]').val(), 10) || 12;
      var icon = $('select[name="sneakypeak[badge_icon]"]').val() || 'star';

      var nudgeX = parseInt($('input[name="sneakypeak[badge_nudge_x]"]').val(), 10);
      if (isNaN(nudgeX)) nudgeX = 0;
      var nudgeY = parseInt($('input[name="sneakypeak[badge_nudge_y]"]').val(), 10);
      if (isNaN(nudgeY)) nudgeY = 0;

      // Single Product Placement & Positioning Settings
      var singlePlacement = $('#sneakypeak-single-badge-position').val() || 'gallery';
      var singleCustomSelector = $('input[name="sneakypeak[single_badge_custom_selector]"]').val() || '';
      var singleCorner = $('select[name="sneakypeak[single_badge_corner]"]').val() || 'top-right';
      var singleNudgeX = parseInt($('input[name="sneakypeak[single_badge_nudge_x]"]').val(), 10);
      if (isNaN(singleNudgeX)) singleNudgeX = 0;
      var singleNudgeY = parseInt($('input[name="sneakypeak[single_badge_nudge_y]"]').val(), 10);
      if (isNaN(singleNudgeY)) singleNudgeY = 0;

      // Phase-specific colors & text
      var text, bgStart, bgEnd, textColor;
      if (currentPreviewPhase === 'live') {
        text = $('input[name="sneakypeak[badge_text_live]"]').val() || 'SALE NOW';
        bgStart = $('input[name="sneakypeak[badge_bg_start_live]"]').val() || '#ff416c';
        bgEnd = $('input[name="sneakypeak[badge_bg_end_live]"]').val() || '#ff4b2b';
        textColor = $('input[name="sneakypeak[badge_text_color_live]"]').val() || '#ffffff';

        // Update pricing display in both mocks for live phase
        $('.sneakypeak-mock-regular-price, .sneakypeak-mock-single-regular-price').addClass('strikethrough');
        $('.sneakypeak-mock-live-price, .sneakypeak-mock-single-live-price').show();
        $('.sneakypeak-mock-teaser-line, .sneakypeak-mock-single-teaser-line').hide();
      } else {
        text = $('input[name="sneakypeak[badge_text_teaser]"]').val() || 'SNEAK PEEK';
        bgStart = $('input[name="sneakypeak[badge_bg_start_teaser]"]').val() || '#ff416c';
        bgEnd = $('input[name="sneakypeak[badge_bg_end_teaser]"]').val() || '#ff4b2b';
        textColor = $('input[name="sneakypeak[badge_text_color_teaser]"]').val() || '#ffffff';

        // Update pricing display in both mocks for teaser phase
        $('.sneakypeak-mock-regular-price, .sneakypeak-mock-single-regular-price').removeClass('strikethrough');
        $('.sneakypeak-mock-live-price, .sneakypeak-mock-single-live-price').hide();
        $('.sneakypeak-mock-teaser-line, .sneakypeak-mock-single-teaser-line').show();
      }

      var iconHtml = '';
      if (icon === 'star') {
        iconHtml = '&#9733;&nbsp;';
      } else if (icon === 'fire') {
        iconHtml = '&#128293;&nbsp;';
      } else if (icon === 'tag') {
        iconHtml = '&#127991;&nbsp;';
      }

      // --- 1. Update Shop Card Mock Badge ---
      var $mockBadgeWrap = $('#sneakypeak-mock-badge-wrap');
      var $mockBadge = $('#sneakypeak-mock-badge');
      var $mockIcon = $('#sneakypeak-mock-icon');
      var $mockText = $('#sneakypeak-mock-text');

      $mockText.text(text);
      if (iconHtml) {
        $mockIcon.html(iconHtml).show();
      } else {
        $mockIcon.empty().hide();
      }

      $mockBadgeWrap.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-corner-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-corner-' + corner + ' sneakypeak-shape-' + shape + ' sneakypeak-size-' + size);

      $mockBadge.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-badge-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-badge-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-badge-shape-' + shape + ' sneakypeak-badge-size-' + size);

      var shopWrapCss = {
        top: 'auto',
        right: 'auto',
        bottom: 'auto',
        left: 'auto',
        transform: 'translate(' + nudgeX + 'px, ' + nudgeY + 'px)'
      };
      if (corner === 'top-right') {
        shopWrapCss.top = '0px'; shopWrapCss.right = '0px';
      } else if (corner === 'top-left') {
        shopWrapCss.top = '0px'; shopWrapCss.left = '0px';
      } else if (corner === 'bottom-left') {
        shopWrapCss.bottom = '0px'; shopWrapCss.left = '0px';
      } else if (corner === 'bottom-right') {
        shopWrapCss.bottom = '0px'; shopWrapCss.right = '0px';
      }
      $mockBadgeWrap.css(shopWrapCss);

      var badgeStyle = {
        background: 'linear-gradient(135deg, ' + bgStart + ' 0%, ' + bgEnd + ' 100%)',
        color: textColor,
        fontSize: fontSize + 'px'
      };
      $mockBadge.css(badgeStyle);

      // --- 2. Update Single Product Mock Badge ---
      var $singleGalleryWrap = $('#sneakypeak-mock-single-gallery-badge-wrap');
      var $singleGalleryBadge = $('#sneakypeak-mock-single-gallery-badge');
      var $singleGalleryIcon = $('#sneakypeak-mock-single-gallery-icon');
      var $singleGalleryText = $('#sneakypeak-mock-single-gallery-text');

      var $singleSummaryContainer = $('#sneakypeak-mock-single-summary-badge-container');
      var $singleSummaryWrap = $('#sneakypeak-mock-single-summary-badge-wrap');
      var $singleSummaryBadge = $('#sneakypeak-mock-single-summary-badge');
      var $singleSummaryIcon = $('#sneakypeak-mock-single-summary-icon');
      var $singleSummaryText = $('#sneakypeak-mock-single-summary-text');
      var $singleCustomNote = $('#sneakypeak-mock-single-custom-note');

      // Update contents and styling on both potential single badge elements
      $singleGalleryText.text(text);
      $singleSummaryText.text(text);

      if (iconHtml) {
        $singleGalleryIcon.html(iconHtml).show();
        $singleSummaryIcon.html(iconHtml).show();
      } else {
        $singleGalleryIcon.empty().hide();
        $singleSummaryIcon.empty().hide();
      }

      $singleGalleryBadge.css(badgeStyle);
      $singleSummaryBadge.css(badgeStyle);

      $singleGalleryWrap.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-corner-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-corner-' + singleCorner + ' sneakypeak-shape-' + shape + ' sneakypeak-size-' + size);

      $singleGalleryBadge.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-badge-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-badge-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-badge-shape-' + shape + ' sneakypeak-badge-size-' + size);

      $singleSummaryWrap.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-shape-' + shape + ' sneakypeak-size-' + size);

      $singleSummaryBadge.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-badge-shape-\S+/g) || []).join(' ') + ' ' +
               (className.match(/(^|\s)sneakypeak-badge-size-\S+/g) || []).join(' ');
      }).addClass('sneakypeak-badge-shape-' + shape + ' sneakypeak-badge-size-' + size);

      // Handle placement mode visibility & coordinates
      if (singlePlacement === 'gallery') {
        $singleGalleryWrap.show();
        $singleSummaryContainer.hide();
        $singleCustomNote.hide();

        var galleryWrapCss = {
          top: 'auto',
          right: 'auto',
          bottom: 'auto',
          left: 'auto',
          transform: 'translate(' + singleNudgeX + 'px, ' + singleNudgeY + 'px)'
        };
        if (singleCorner === 'top-right') {
          galleryWrapCss.top = '0px'; galleryWrapCss.right = '0px';
        } else if (singleCorner === 'top-left') {
          galleryWrapCss.top = '0px'; galleryWrapCss.left = '0px';
        } else if (singleCorner === 'bottom-left') {
          galleryWrapCss.bottom = '0px'; galleryWrapCss.left = '0px';
        } else if (singleCorner === 'bottom-right') {
          galleryWrapCss.bottom = '0px'; galleryWrapCss.right = '0px';
        }
        $singleGalleryWrap.css(galleryWrapCss);

      } else if (singlePlacement === 'summary') {
        $singleGalleryWrap.hide();
        $singleSummaryContainer.show();
        $singleCustomNote.hide();

        $singleSummaryWrap.css({
          transform: 'translate(' + singleNudgeX + 'px, ' + singleNudgeY + 'px)'
        });

      } else if (singlePlacement === 'custom') {
        $singleGalleryWrap.hide();
        $singleSummaryContainer.show();
        $singleCustomNote.show();

        var trimmedSelector = $.trim(singleCustomSelector);
        if (trimmedSelector === '') {
          $('#sneakypeak-mock-single-custom-note-text').text("No selector set.");
        } else {
          $('#sneakypeak-mock-single-custom-note-text').text("Preview is approximate. The real position depends on your theme’s selector.");
        }

        $singleSummaryWrap.css({
          transform: 'translate(' + singleNudgeX + 'px, ' + singleNudgeY + 'px)'
        });
      }
    }

    // Phase toggle switch for live mock preview
    $('.sneakypeak-mock-phase-toggle').on('click', function (e) {
      e.preventDefault();
      $('.sneakypeak-mock-phase-toggle').removeClass('active button-primary').addClass('button-secondary');
      $(this).removeClass('button-secondary').addClass('active button-primary');
      currentPreviewPhase = $(this).data('phase') || 'teaser';
      updateBadgePreview();
    });

    // Event listeners on badge & single placement form inputs
    $('select[name^="sneakypeak[badge_"], input[name^="sneakypeak[badge_"], select[name^="sneakypeak[single_badge_"], input[name^="sneakypeak[single_badge_"]').on('input change', function () {
      updateBadgePreview();
    });

    // WP Color Picker change event integration
    if ($.fn.wpColorPicker) {
      $('.sneakypeak-color-field').wpColorPicker({
        change: function () {
          setTimeout(updateBadgePreview, 50);
        },
        clear: function () {
          setTimeout(updateBadgePreview, 50);
        }
      });
    }

    // Teaser Calculator Masking
    function maskPriceNumeric(amount, rule) {
      var numStr = Number(amount).toFixed(2);
      var parts = numStr.split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      var formatted = parts.join('.');

      if (rule === 'mask_last_3') {
        var chars = formatted.split('');
        var maskedCount = 0;
        for (var i = chars.length - 1; i >= 0; i--) {
          if (/\d/.test(chars[i])) {
            chars[i] = '?';
            maskedCount++;
            if (maskedCount >= 3) break;
          }
        }
        return chars.join('');
      }

      // Default keep_first
      var charsAll = formatted.split('');
      var firstFound = false;
      for (var j = 0; j < charsAll.length; j++) {
        if (/\d/.test(charsAll[j])) {
          if (!firstFound) {
            firstFound = true;
          } else {
            charsAll[j] = '?';
          }
        }
      }
      return charsAll.join('');
    }

    function updateTeaserCalculator() {
      var samplePrice = parseFloat($('#sneakypeak-calc-sample-price').val()) || 0;
      var rule = $('select[name="sneakypeak[mask_rule]"]').val() || 'keep_first';
      var label = $('input[name="sneakypeak[teaser_label]"]').val() || 'Sale Price:';
      var prefix = $('input[name="sneakypeak[teaser_variable_prefix]"]').val() || 'From';
      var symbol = window.sneakypeakAdminData ? window.sneakypeakAdminData.currencySymbol : '₱';

      var fontFamily = $('select[name="sneakypeak[teaser_font_family]"]').val() || 'inherit';
      var customFontUrl = $.trim($('input[name="sneakypeak[teaser_font_custom_url]"]').val() || '');
      var customFontName = $.trim($('input[name="sneakypeak[teaser_font_custom_name]"]').val() || 'SPCustomFont');
      var customFontSize = parseInt($('input[name="sneakypeak[teaser_font_size]"]').val(), 10) || 0;

      var maskedSimple = maskPriceNumeric(samplePrice, rule);
      var maskedVariable = prefix ? prefix + ' ' + symbol + maskedSimple : symbol + maskedSimple;

      // Update Calculator output texts
      $('#sneakypeak-calc-output-simple').text(label + ' ' + symbol + maskedSimple);
      $('#sneakypeak-calc-output-variable').text(label + ' ' + maskedVariable);

      // Update Mock Card teaser line texts
      $('.sneakypeak-mock-teaser-line .sneakypeak-teaser-label, .sneakypeak-mock-single-teaser-line .sneakypeak-teaser-label').text(label);
      $('.sneakypeak-mock-teaser-line .sneakypeak-teaser-price, .sneakypeak-mock-single-teaser-line .sneakypeak-teaser-price').text(symbol + maskedSimple);

      // Manage Fonts in Admin Head
      var resolvedFamily = 'inherit';
      var $gFontLink = $('#sneakypeak-admin-gfont');
      var $customFontStyle = $('#sneakypeak-admin-custom-font');

      if (fontFamily === 'inherit') {
        $gFontLink.remove();
        $customFontStyle.remove();
        resolvedFamily = 'inherit';

      } else if (fontFamily === 'custom') {
        $gFontLink.remove();
        if (customFontUrl && /\.(woff2|woff|ttf)(\?.*)?$/i.test(customFontUrl)) {
          var format = 'woff2';
          if (/\.ttf(\?.*)?$/i.test(customFontUrl)) {
            format = 'truetype';
          } else if (/\.woff(\?.*)?$/i.test(customFontUrl)) {
            format = 'woff';
          }
          var cssRule = '@font-face { font-family: "' + customFontName + '"; src: url("' + customFontUrl + '") format("' + format + '"); font-weight: 400 700; font-display: swap; }';
          if (!$customFontStyle.length) {
            $('head').append('<style id="sneakypeak-admin-custom-font">' + cssRule + '</style>');
          } else {
            $customFontStyle.text(cssRule);
          }
          resolvedFamily = '"' + customFontName + '", sans-serif';
        } else {
          $customFontStyle.remove();
          resolvedFamily = 'inherit';
        }

      } else if (gFontMap[fontFamily]) {
        $customFontStyle.remove();
        var gFontUrl = 'https://fonts.googleapis.com/css2?family=' + gFontMap[fontFamily] + '&display=swap';
        if (!$gFontLink.length) {
          $('head').append('<link id="sneakypeak-admin-gfont" rel="stylesheet" href="' + gFontUrl + '">');
        } else if ($gFontLink.attr('href') !== gFontUrl) {
          $gFontLink.attr('href', gFontUrl);
        }
        var capitalized = fontFamily.charAt(0).toUpperCase() + fontFamily.slice(1);
        resolvedFamily = '"' + capitalized + '", sans-serif';

      } else {
        $gFontLink.remove();
        $customFontStyle.remove();
        resolvedFamily = 'inherit';
      }

      // Apply typography to all teaser price previews
      var $typographyTargets = $([
        '#sneakypeak-calc-output-simple',
        '#sneakypeak-calc-output-variable',
        '.sneakypeak-mock-teaser-line',
        '.sneakypeak-mock-teaser-line *',
        '.sneakypeak-mock-single-teaser-line',
        '.sneakypeak-mock-single-teaser-line *'
      ].join(', '));

      $typographyTargets.css('font-family', resolvedFamily);

      var $sizeTargets = $([
        '#sneakypeak-calc-output-simple',
        '#sneakypeak-calc-output-variable',
        '.sneakypeak-mock-teaser-line .sneakypeak-teaser-price',
        '.sneakypeak-mock-single-teaser-line .sneakypeak-teaser-price'
      ].join(', '));

      if (customFontSize > 0) {
        $sizeTargets.css('font-size', customFontSize + 'px');
      } else {
        $sizeTargets.css('font-size', '');
      }
    }

    $('#sneakypeak-calc-sample-price, select[name="sneakypeak[mask_rule]"], input[name="sneakypeak[teaser_label]"], input[name="sneakypeak[teaser_variable_prefix]"], select[name="sneakypeak[teaser_font_family]"], input[name="sneakypeak[teaser_font_custom_url]"], input[name="sneakypeak[teaser_font_custom_name]"], input[name="sneakypeak[teaser_font_size]"]')
      .on('input change', updateTeaserCalculator);

    // Single badge position change toggle
    $('#sneakypeak-single-badge-position').on('change', function () {
      if ($(this).val() === 'custom') {
        $('#sneakypeak-single-custom-selector-row').show();
      } else {
        $('#sneakypeak-single-custom-selector-row').hide();
      }
      updateBadgePreview();
    });

    $('input[name="sneakypeak[single_badge_custom_selector]"]').on('input change', function () {
      updateBadgePreview();
    });

    // Teaser font family toggle
    $('#sneakypeak-teaser-font-family').on('change', function () {
      if ($(this).val() === 'custom') {
        $('#sneakypeak-custom-font-row').show();
      } else {
        $('#sneakypeak-custom-font-row').hide();
      }
      updateTeaserCalculator();
    });

    // WordPress Media Uploader for custom font file (.woff2, .woff, .ttf)
    var fontMediaUploader;
    $('#sneakypeak-upload-font-button').on('click', function (e) {
      e.preventDefault();
      if (fontMediaUploader) {
        fontMediaUploader.open();
        return;
      }

      if (window.sneakypeakAdminData && window.sneakypeakAdminData.postId) {
        if (typeof wp !== 'undefined' && wp.media && wp.media.view && wp.media.view.settings) {
          if (!wp.media.view.settings.post) {
            wp.media.view.settings.post = {};
          }
          wp.media.view.settings.post.id = window.sneakypeakAdminData.postId;
        }
      }

      fontMediaUploader = wp.media({
        title: 'Choose or Upload Font File',
        button: { text: 'Use this font' },
        multiple: false
      });

      fontMediaUploader.on('select', function () {
        var attachment = fontMediaUploader.state().get('selection').first().toJSON();
        $('#sneakypeak-custom-font-url').val(attachment.url).trigger('change');
      });

      fontMediaUploader.open();
    });

    // Initial run
    updateBadgePreview();
    updateTeaserCalculator();
  });
})(jQuery);
