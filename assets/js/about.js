/**
 * Convermetry — About page.
 *
 * Highlights the sticky nav link for whichever section is currently in view.
 * Pure progressive enhancement: the nav is plain anchor links, so with this
 * file blocked every jump still works — only the active-link highlight is
 * lost.
 */
(function () {
    'use strict';

    var links = Array.prototype.slice.call(
        document.querySelectorAll('.cvm-about-nav-link[data-cvm-section]')
    );

    if (!links.length || typeof IntersectionObserver !== 'function') {
        return;
    }

    var linkById = {};
    var sections = [];

    links.forEach(function (link) {
        var id = link.getAttribute('data-cvm-section');
        var section = document.getElementById(id);

        if (section) {
            linkById[id] = link;
            sections.push(section);
        }
    });

    if (!sections.length) {
        return;
    }

    var visible = Object.create(null);

    function setActive(id) {
        links.forEach(function (link) {
            link.classList.toggle(
                'is-active',
                link.getAttribute('data-cvm-section') === id
            );
        });
    }

    /**
     * The topmost section currently intersecting wins. Document order is the
     * tie-break, so scrolling never flickers between two adjacent sections
     * that are both partly on screen.
     */
    function refresh() {
        for (var i = 0; i < sections.length; i++) {
            if (visible[sections[i].id]) {
                setActive(sections[i].id);
                return;
            }
        }
    }

    var observer = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (entry) {
                visible[entry.target.id] = entry.isIntersecting;
            });
            refresh();
        },
        {
            // Discounts the sticky bar's own band at the top of the viewport,
            // and most of the lower half, so "current" means "near the top of
            // what you are reading".
            rootMargin: '-120px 0px -55% 0px',
            threshold: 0
        }
    );

    sections.forEach(function (section) {
        observer.observe(section);
    });

    // Clicking a link should win immediately, rather than waiting for the
    // smooth scroll to settle.
    links.forEach(function (link) {
        link.addEventListener('click', function () {
            setActive(link.getAttribute('data-cvm-section'));
        });
    });
})();
