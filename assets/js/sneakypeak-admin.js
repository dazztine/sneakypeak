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

    var $mockBadgeWrap = $('#sneakypeak-mock-badge-wrap');
    var $mockBadge = $('#sneakypeak-mock-badge');
    var $mockIcon = $('#sneakypeak-mock-icon');
    var $mockText = $('#sneakypeak-mock-text');

    var currentPreviewPhase = 'teaser'; // 'teaser' or 'live'

    function updateBadgePreview() {
      var corner = $('select[name="sneakypeak[badge_corner]"]').val() || 'top-right';
      var shape = $('select[name="sneakypeak[badge_shape]"]').val() || 'ribbon';
      var size = $('select[name="sneakypeak[badge_size]"]').val() || 'medium';
      var fontSize = parseInt($('input[name="sneakypeak[badge_font_size]"]').val(), 10) || 12;
      var icon = $('select[name="sneakypeak[badge_icon]"]').val() || 'star';

      var nudgeX = parseInt($('input[name="sneakypeak[badge_nudge_x]"]').val(), 10);
      if (isNaN(nudgeX)) {
        nudgeX = 0;
      }
      var nudgeY = parseInt($('input[name="sneakypeak[badge_nudge_y]"]').val(), 10);
      if (isNaN(nudgeY)) {
        nudgeY = 0;
      }

      var text, bgStart, bgEnd, textColor;
      if (currentPreviewPhase === 'live') {
        text = $('input[name="sneakypeak[badge_text_live]"]').val() || 'SALE NOW';
        bgStart = $('input[name="sneakypeak[badge_bg_start_live]"]').val() || '#ff416c';
        bgEnd = $('input[name="sneakypeak[badge_bg_end_live]"]').val() || '#ff4b2b';
        textColor = $('input[name="sneakypeak[badge_text_color_live]"]').val() || '#ffffff';
      } else {
        text = $('input[name="sneakypeak[badge_text_teaser]"]').val() || 'SNEAK PEEK';
        bgStart = $('input[name="sneakypeak[badge_bg_start_teaser]"]').val() || '#ff416c';
        bgEnd = $('input[name="sneakypeak[badge_bg_end_teaser]"]').val() || '#ff4b2b';
        textColor = $('input[name="sneakypeak[badge_text_color_teaser]"]').val() || '#ffffff';
      }

      // Update text
      $mockText.text(text);

      // Update icon
      if (icon === 'star') {
        $mockIcon.html('&#9733;&nbsp;').show();
      } else if (icon === 'fire') {
        $mockIcon.html('&#128293;&nbsp;').show();
      } else if (icon === 'tag') {
        $mockIcon.html('&#127991;&nbsp;').show();
      } else {
        $mockIcon.empty().hide();
      }

      // Update classes
      $mockBadgeWrap.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-corner-\S+/g) || []).join(' ');
      }).removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-shape-\S+/g) || []).join(' ');
      }).removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-size-\S+/g) || []).join(' ');
      });

      $mockBadgeWrap.addClass('sneakypeak-corner-' + corner);
      $mockBadgeWrap.addClass('sneakypeak-shape-' + shape);
      $mockBadgeWrap.addClass('sneakypeak-size-' + size);

      $mockBadge.removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-badge-shape-\S+/g) || []).join(' ');
      }).removeClass(function (index, className) {
        return (className.match(/(^|\s)sneakypeak-badge-size-\S+/g) || []).join(' ');
      });

      $mockBadge.addClass('sneakypeak-badge-shape-' + shape);
      $mockBadge.addClass('sneakypeak-badge-size-' + size);

      // Position anchor based on corner and apply signed nudge transform
      var wrapCss = {
        top: '',
        right: '',
        bottom: '',
        left: '',
        transform: 'translate(' + nudgeX + 'px, ' + nudgeY + 'px)'
      };

      if (corner === 'top-right') {
        wrapCss.top = '0px';
        wrapCss.right = '0px';
      } else if (corner === 'top-left') {
        wrapCss.top = '0px';
        wrapCss.left = '0px';
      } else if (corner === 'bottom-left') {
        wrapCss.bottom = '0px';
        wrapCss.left = '0px';
      } else if (corner === 'bottom-right') {
        wrapCss.bottom = '0px';
        wrapCss.right = '0px';
      }
      $mockBadgeWrap.css(wrapCss);

      // Badge style
      $mockBadge.css({
        background: 'linear-gradient(135deg, ' + bgStart + ' 0%, ' + bgEnd + ' 100%)',
        color: textColor,
        fontSize: fontSize + 'px'
      });
    }

    // Phase toggle switch for live mock preview
    $('.sneakypeak-mock-phase-toggle').on('click', function (e) {
      e.preventDefault();
      $('.sneakypeak-mock-phase-toggle').removeClass('active button-primary').addClass('button-secondary');
      $(this).removeClass('button-secondary').addClass('active button-primary');
      currentPreviewPhase = $(this).data('phase') || 'teaser';
      updateBadgePreview();
    });

    // Event listeners on form inputs
    $('select[name^="sneakypeak[badge_"], input[name^="sneakypeak[badge_"]').on('input change', function () {
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

    // Teaser Calculator
    function maskPriceNumeric(amount, rule) {
      var numStr = Number(amount).toFixed(2);
      // split with thousand commas if >= 1000
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
      var customFontUrl = $('input[name="sneakypeak[teaser_font_custom_url]"]').val() || '';
      var customFontName = $('input[name="sneakypeak[teaser_font_custom_name]"]').val() || 'SPCustomFont';
      var customFontSize = parseInt($('input[name="sneakypeak[teaser_font_size]"]').val(), 10) || 0;

      var maskedSimple = maskPriceNumeric(samplePrice, rule);
      var maskedVariable = prefix ? prefix + ' ' + symbol + maskedSimple : symbol + maskedSimple;

      $('#sneakypeak-calc-output-simple').text(label + ' ' + symbol + maskedSimple);
      $('#sneakypeak-calc-output-variable').text(label + ' ' + maskedVariable);

      // Dynamically load Google Font in admin preview if selected
      var resolvedFamily = 'inherit';
      if (fontFamily === 'custom') {
        if (customFontUrl) {
          var fontFaceId = 'sneakypeak-custom-font-face';
          var $existingStyle = $('#' + fontFaceId);
          var cssRule = '@font-face { font-family: "' + customFontName + '"; src: url("' + customFontUrl + '"); font-weight: 700 800; font-display: swap; }';
          if (!$existingStyle.length) {
            $('head').append('<style id="' + fontFaceId + '">' + cssRule + '</style>');
          } else {
            $existingStyle.text(cssRule);
          }
          resolvedFamily = '"' + customFontName + '", sans-serif';
        }
      } else if (fontFamily !== 'inherit') {
        var gFontMap = {
          'jost': 'Jost:wght@700;800',
          'montserrat': 'Montserrat:wght@700;800',
          'poppins': 'Poppins:wght@700;800',
          'outfit': 'Outfit:wght@700;800',
          'inter': 'Inter:wght@700;800',
          'questrial': 'Questrial'
        };
        if (gFontMap[fontFamily]) {
          var linkId = 'sneakypeak-gfont-' + fontFamily;
          if (!$('#' + linkId).length) {
            $('head').append('<link id="' + linkId + '" rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' + gFontMap[fontFamily] + '&display=swap">');
          }
          resolvedFamily = '"' + fontFamily.charAt(0).toUpperCase() + fontFamily.slice(1) + '", sans-serif';
        }
      }

      var calcCss = { 'font-family': resolvedFamily };
      if (customFontSize > 0) {
        calcCss['font-size'] = customFontSize + 'px';
      } else {
        calcCss['font-size'] = '';
      }
      $('#sneakypeak-calc-output-simple, #sneakypeak-calc-output-variable').css(calcCss);
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
    $('#sneakypeak-upload-font-button').on('click', function (e) {
      e.preventDefault();
      var mediaUploader;
      if (mediaUploader) {
        mediaUploader.open();
        return;
      }
      mediaUploader = wp.media({
        title: 'Choose or Upload Font File',
        button: { text: 'Use this font' },
        multiple: false
      });

      mediaUploader.on('select', function () {
        var attachment = mediaUploader.state().get('selection').first().toJSON();
        $('#sneakypeak-custom-font-url').val(attachment.url).trigger('change');
      });

      mediaUploader.open();
    });

    // Initial run
    updateBadgePreview();
    updateTeaserCalculator();
  });
})(jQuery);
