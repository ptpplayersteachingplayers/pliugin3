<?php
/**
 * PTP Dark Header v88
 * 
 * Forces the site header to be dark/black to match PTP branding.
 * Injects inline CSS with maximum specificity at multiple hook points.
 * 
 * @since 88.7.2
 */

defined('ABSPATH') || exit;

/**
 * Inject dark header CSS inline (priority 1 - very early)
 */
add_action('wp_head', function() {
    if (is_admin()) return;
    ?>
    <style id="ptp-dark-header">
    /*
     * PTP Dark Header Override
     * Forces the site header to be dark (#0A0A0A) to match PTP branding.
     * Uses maximum specificity and !important declarations.
     */
    
    /* DARK HEADER BACKGROUND - Nuclear option */
    html body header,
    html body > header,
    html body #masthead,
    html body .site-header,
    html body header.header,
    html body .elementor-location-header,
    html body .elementor-location-header > .elementor-container,
    html body .elementor-location-header .elementor-section,
    html body .elementor-location-header .elementor-element,
    html body .elementor-location-header .elementor-container,
    html body .elementor-location-header .elementor-column,
    html body .elementor-location-header .elementor-widget-wrap,
    html body .elementor-location-header .e-con,
    html body .elementor-location-header .e-con-inner,
    html body [data-elementor-type="header"],
    html body [data-elementor-type="header"] > .elementor-section-wrap,
    html body [data-elementor-type="header"] .elementor-section,
    html body [data-elementor-type="header"] .elementor-container,
    html body [data-elementor-type="header"] .elementor-element,
    html body [data-elementor-type="header"] .elementor-column,
    html body [data-elementor-type="header"] .elementor-widget-wrap,
    html body [data-elementor-type="header"] .e-con,
    html body [data-elementor-type="header"] .e-con-inner,
    body.elementor-page header,
    body.elementor-page .elementor-location-header,
    body.elementor-page [data-elementor-type="header"],
    .elementor .elementor-location-header,
    .elementor [data-elementor-type="header"],
    #site-header,
    .ast-header-sections,
    .theme-header {
        background: #0A0A0A !important;
        background-color: #0A0A0A !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }
    
    /* Remove any background overlays/images */
    html body .elementor-location-header .elementor-background-overlay,
    html body [data-elementor-type="header"] .elementor-background-overlay {
        display: none !important;
    }
    
    /* Inner elements transparent */
    html body .elementor-location-header .elementor-section-wrap,
    html body .elementor-location-header .elementor-inner,
    html body .elementor-location-header .elementor-section-boxed > .elementor-container,
    html body [data-elementor-type="header"] .elementor-section-wrap,
    html body [data-elementor-type="header"] .elementor-inner {
        background: transparent !important;
        background-color: transparent !important;
    }
    
    /* NAV LINKS - White text */
    html body .elementor-location-header a,
    html body .elementor-location-header .elementor-nav-menu a,
    html body .elementor-location-header .elementor-item,
    html body .elementor-location-header .menu-item > a,
    html body [data-elementor-type="header"] a,
    html body [data-elementor-type="header"] .elementor-nav-menu a,
    html body [data-elementor-type="header"] .elementor-item,
    html body [data-elementor-type="header"] .menu-item > a,
    .elementor-location-header .elementor-widget-nav-menu a,
    [data-elementor-type="header"] .elementor-widget-nav-menu a,
    html body .site-header a,
    html body #masthead a,
    html body header nav a {
        color: rgba(255, 255, 255, 0.85) !important;
    }
    
    /* Nav hover - Gold */
    html body .elementor-location-header a:hover,
    html body .elementor-location-header .elementor-item:hover,
    html body [data-elementor-type="header"] a:hover,
    html body [data-elementor-type="header"] .elementor-item:hover,
    .elementor-location-header .elementor-item:hover,
    [data-elementor-type="header"] .elementor-item:hover {
        color: #FCB900 !important;
    }
    
    /* Nav active - Gold */
    html body .elementor-location-header .elementor-item.elementor-item-active,
    html body .elementor-location-header .current-menu-item > a,
    html body [data-elementor-type="header"] .elementor-item.elementor-item-active,
    html body [data-elementor-type="header"] .current-menu-item > a,
    .elementor-item.elementor-item-active {
        color: #FCB900 !important;
    }
    
    /* DROPDOWN MENUS - Dark */
    html body .elementor-nav-menu--dropdown,
    html body .elementor-nav-menu--dropdown.elementor-nav-menu__container,
    html body .sub-menu,
    .elementor-nav-menu--dropdown,
    .elementor-nav-menu--dropdown.elementor-nav-menu__container {
        background: #1a1a1a !important;
        background-color: #1a1a1a !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4) !important;
    }
    
    html body .elementor-nav-menu--dropdown a,
    html body .elementor-nav-menu--dropdown .elementor-item,
    .elementor-nav-menu--dropdown a,
    .elementor-nav-menu--dropdown .elementor-item {
        color: rgba(255, 255, 255, 0.7) !important;
    }
    
    html body .elementor-nav-menu--dropdown a:hover,
    html body .elementor-nav-menu--dropdown .elementor-item:hover,
    .elementor-nav-menu--dropdown a:hover,
    .elementor-nav-menu--dropdown .elementor-item:hover {
        background: rgba(255, 255, 255, 0.05) !important;
        color: #FFFFFF !important;
    }
    
    /* HAMBURGER MENU - White */
    html body .elementor-menu-toggle,
    html body .elementor-menu-toggle i,
    html body .elementor-menu-toggle svg,
    .elementor-menu-toggle,
    .elementor-menu-toggle i,
    .elementor-menu-toggle svg {
        color: #FFFFFF !important;
        fill: #FFFFFF !important;
    }
    
    .elementor-menu-toggle i::before,
    .elementor-menu-toggle i::after {
        background-color: #FFFFFF !important;
    }
    
    /* BUTTONS - Gold */
    html body .elementor-location-header .elementor-button,
    html body [data-elementor-type="header"] .elementor-button,
    .elementor-location-header .elementor-button,
    [data-elementor-type="header"] .elementor-button {
        background: #FCB900 !important;
        background-color: #FCB900 !important;
        color: #0A0A0A !important;
        border-color: #FCB900 !important;
    }
    
    html body .elementor-location-header .elementor-button:hover,
    html body [data-elementor-type="header"] .elementor-button:hover {
        background: #E5A800 !important;
        background-color: #E5A800 !important;
    }
    
    /* REGISTER button */
    html body .elementor-location-header a[href*="register"],
    html body [data-elementor-type="header"] a[href*="register"] {
        background: #FCB900 !important;
        background-color: #FCB900 !important;
        color: #0A0A0A !important;
    }
    
    /* STICKY HEADER */
    html body .elementor-sticky--active,
    html body .elementor-location-header.elementor-sticky--active,
    html body [data-elementor-type="header"].elementor-sticky--active,
    .elementor-sticky--active {
        background: rgba(10, 10, 10, 0.98) !important;
        background-color: rgba(10, 10, 10, 0.98) !important;
        -webkit-backdrop-filter: blur(20px) !important;
        backdrop-filter: blur(20px) !important;
    }
    
    /* Fix duplicate navigation elements */
    body.elementor-page .secondary-navigation,
    body.elementor-page #secondary-navigation,
    body.elementor-page .site-header:not(.elementor-element),
    body.elementor-page header:not(.elementor-element):not(.ft-header):not(.ptp-header) {
        display: none !important;
    }
    
    /* If page has PTP custom header, hide Elementor header */
    body.ptp-custom-template .elementor-location-header,
    body.ptp-custom-template header.elementor-element,
    body.ptp-custom-template #masthead,
    body.ptp-custom-template .site-header,
    body.ptp-custom-template .theme-header {
        display: none !important;
    }
    
    /* Z-index stacking */
    .elementor-location-header,
    .elementor-sticky,
    [data-elementor-type="header"] {
        z-index: 1000 !important;
    }
    
    .ft-header,
    .ptp-header {
        z-index: 1001 !important;
    }
    
    /* Admin bar adjustment */
    body.admin-bar .elementor-location-header.elementor-sticky,
    body.admin-bar .elementor-sticky--active {
        top: 32px !important;
    }
    
    @media screen and (max-width: 782px) {
        body.admin-bar .elementor-location-header.elementor-sticky,
        body.admin-bar .elementor-sticky--active {
            top: 46px !important;
        }
    }
    </style>
    <?php
}, 1);

/**
 * Also add at priority 999 to ensure it overrides everything
 */
add_action('wp_head', function() {
    if (is_admin()) return;
    ?>
    <style id="ptp-dark-header-late">
    /* Late-loaded dark header override (priority 999) */
    html body .elementor-location-header,
    html body .elementor-location-header .elementor-container,
    html body .elementor-location-header .elementor-section,
    html body [data-elementor-type="header"],
    html body [data-elementor-type="header"] .elementor-section,
    html body [data-elementor-type="header"] .elementor-container {
        background: #0A0A0A !important;
        background-color: #0A0A0A !important;
    }
    
    html body .elementor-location-header a,
    html body [data-elementor-type="header"] a {
        color: rgba(255, 255, 255, 0.85) !important;
    }
    
    html body .elementor-location-header a:hover,
    html body [data-elementor-type="header"] a:hover {
        color: #FCB900 !important;
    }
    </style>
    <?php
}, 999);

/**
 * Add dark header styles to wp_footer as final override
 */
add_action('wp_footer', function() {
    if (is_admin()) return;
    ?>
    <style id="ptp-dark-header-footer">
    /* Footer-injected dark header (absolute last override) */
    .elementor-location-header,
    [data-elementor-type="header"],
    .elementor-location-header .elementor-section,
    [data-elementor-type="header"] .elementor-section,
    .elementor-location-header .e-con,
    [data-elementor-type="header"] .e-con {
        background: #0A0A0A !important;
    }
    </style>
    
    <script id="ptp-dark-header-js">
    (function() {
        // Force dark header via JavaScript - overrides inline styles
        function forceBlackHeader() {
            var selectors = [
                'header',
                '.site-header',
                '#masthead',
                '.elementor-location-header',
                '[data-elementor-type="header"]',
                '.elementor-location-header .elementor-section',
                '.elementor-location-header .elementor-container',
                '.elementor-location-header .elementor-column',
                '.elementor-location-header .elementor-widget-wrap',
                '.elementor-location-header .e-con',
                '.elementor-location-header .e-con-inner',
                '[data-elementor-type="header"] .elementor-section',
                '[data-elementor-type="header"] .elementor-container',
                '[data-elementor-type="header"] .elementor-column',
                '[data-elementor-type="header"] .elementor-widget-wrap',
                '[data-elementor-type="header"] .e-con',
                '[data-elementor-type="header"] .e-con-inner'
            ];
            
            selectors.forEach(function(sel) {
                var els = document.querySelectorAll(sel);
                els.forEach(function(el) {
                    el.style.setProperty('background', '#0A0A0A', 'important');
                    el.style.setProperty('background-color', '#0A0A0A', 'important');
                    el.style.setProperty('background-image', 'none', 'important');
                });
            });
            
            // Force nav links white
            var navLinks = document.querySelectorAll('.elementor-location-header a, [data-elementor-type="header"] a');
            navLinks.forEach(function(link) {
                if (!link.classList.contains('elementor-button') && !link.closest('.elementor-button')) {
                    link.style.setProperty('color', 'rgba(255,255,255,0.85)', 'important');
                }
            });
        }
        
        // Run immediately
        forceBlackHeader();
        
        // Run after DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', forceBlackHeader);
        }
        
        // Run after full load
        window.addEventListener('load', forceBlackHeader);
        
        // Run again after a delay (for Elementor dynamic loading)
        setTimeout(forceBlackHeader, 100);
        setTimeout(forceBlackHeader, 500);
        setTimeout(forceBlackHeader, 1000);
        
        // MutationObserver to catch any dynamic changes
        var observer = new MutationObserver(function(mutations) {
            forceBlackHeader();
        });
        
        var header = document.querySelector('.elementor-location-header, [data-elementor-type="header"]');
        if (header) {
            observer.observe(header, { attributes: true, childList: true, subtree: true });
        }
    })();
    </script>
    <?php
}, 9999);
