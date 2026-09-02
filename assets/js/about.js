/**
 * Convermetry — About page.
 *
 * Two independent enhancements, deliberately in separate IIFEs: the sticky-nav
 * highlighter bails early on browsers without IntersectionObserver, and the
 * hook accordion must keep working when it does.
 *
 * Pure progressive enhancement throughout. The nav is plain anchor links, so
 * with this file blocked every jump still works; the hook detail panels are
 * revealed by a <noscript> rule, so their content stays reachable too.
 */

/* Hook reference — "Learn More" / "Collapse" accordions.
 *
 * Delegated from the document rather than bound per button: the hook reference
 * renders eighty-five of these, and one listener is cheaper than eighty-five
 * both to attach and to keep in sync. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var button = e.target.closest && e.target.closest('.cvm-about-hook-toggle');
        if (!button) {
            return;
        }

        var panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) {
            return;
        }

        // aria-expanded is the single source of truth, so the button label and
        // the panel can never disagree about which state they are in.
        var expanded = button.getAttribute('aria-expanded') === 'true';

        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        button.textContent = expanded ? 'Learn More' : 'Collapse';
        panel.hidden = expanded;
    });
})();

/* Sticky nav — highlights the section currently in view. */
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
