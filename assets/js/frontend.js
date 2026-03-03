/**
 * Maharat Multilingual – Frontend JavaScript
 *
 * Handles the dropdown language switcher toggle and keyboard navigation.
 *
 * @package Maharat\Multilingual
 */

(function () {
    'use strict';

    /* =================================================================
     * Dropdown Toggle
     * ================================================================= */

    function initDropdowns() {
        var dropdowns = document.querySelectorAll('.maharat-switcher--dropdown');

        dropdowns.forEach(function (dropdown) {
            var current = dropdown.querySelector('.maharat-switcher__current');
            if (!current) return;

            // Toggle on click.
            current.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = dropdown.classList.contains('is-open');

                // Close all other dropdowns first.
                closeAllDropdowns();

                if (!isOpen) {
                    dropdown.classList.add('is-open');
                    current.setAttribute('aria-expanded', 'true');
                }
            });

            // Keyboard navigation.
            current.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    current.click();
                } else if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });

            // Keyboard navigation within the list.
            var list = dropdown.querySelector('.maharat-switcher__list');
            if (list) {
                list.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        closeAllDropdowns();
                        current.focus();
                    }

                    var items = list.querySelectorAll('a');
                    var currentIndex = Array.prototype.indexOf.call(items, document.activeElement);

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        var next = currentIndex + 1;
                        if (next < items.length) items[next].focus();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        var prev = currentIndex - 1;
                        if (prev >= 0) items[prev].focus();
                    }
                });
            }
        });

        // Close dropdowns when clicking outside.
        document.addEventListener('click', function () {
            closeAllDropdowns();
        });
    }

    function closeAllDropdowns() {
        var openDropdowns = document.querySelectorAll('.maharat-switcher--dropdown.is-open');
        openDropdowns.forEach(function (dd) {
            dd.classList.remove('is-open');
            var btn = dd.querySelector('.maharat-switcher__current');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    /* =================================================================
     * Prevent propagation on switcher list clicks
     * ================================================================= */

    function initListClicks() {
        var lists = document.querySelectorAll('.maharat-switcher__list');
        lists.forEach(function (list) {
            list.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });
    }

    /* =================================================================
     * Init on DOM Ready
     * ================================================================= */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initDropdowns();
            initListClicks();
        });
    } else {
        initDropdowns();
        initListClicks();
    }

})();
