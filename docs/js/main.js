/* ============================================
   AETHER Documentation Site - JS
   Handles: tab switching, mobile nav, scroll
   animations, sidebar active state, copy code
   ============================================ */

document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile Navigation Toggle ──
    const toggle = document.querySelector('.mobile-toggle');
    const nav = document.querySelector('.header-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            nav.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !nav.contains(e.target)) {
                nav.classList.remove('open');
            }
        });
    }

    // ── Code Tab Switching ──
    document.querySelectorAll('.code-tabs').forEach(function (tabContainer) {
        const tabs = tabContainer.querySelectorAll('.code-tab');
        const parentExample = tabContainer.closest('.code-example');
        if (!parentExample) return;
        const panels = parentExample.querySelectorAll('.code-panel');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                const target = tab.getAttribute('data-tab');

                tabs.forEach(function (t) { t.classList.remove('active'); });
                panels.forEach(function (p) { p.classList.remove('active'); });

                tab.classList.add('active');
                var panel = parentExample.querySelector('[data-panel="' + target + '"]');
                if (panel) panel.classList.add('active');
            });
        });
    });

    // ── Scroll-triggered Animations ──
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    document.querySelectorAll('.feature-card, .arch-card, .doc-link, .metric').forEach(function (el) {
        observer.observe(el);
    });

    // ── Active Sidebar Link (docs pages) ──
    var sidebarLinks = document.querySelectorAll('.sidebar-nav a');
    if (sidebarLinks.length > 0) {
        var sections = [];
        sidebarLinks.forEach(function (link) {
            var href = link.getAttribute('href');
            if (href && href.charAt(0) === '#') {
                var section = document.querySelector(href);
                if (section) sections.push({ el: section, link: link });
            }
        });

        if (sections.length > 0) {
            window.addEventListener('scroll', function () {
                var scrollPos = window.scrollY + 120;
                var current = sections[0];

                sections.forEach(function (s) {
                    if (s.el.offsetTop <= scrollPos) {
                        current = s;
                    }
                });

                sidebarLinks.forEach(function (l) { l.classList.remove('active'); });
                current.link.classList.add('active');
            });
        }
    }

    // ── Copy Code Button ──
    document.querySelectorAll('pre').forEach(function (block) {
        var btn = document.createElement('button');
        btn.textContent = 'Copy';
        btn.style.cssText = 'position:absolute;top:8px;right:8px;padding:4px 10px;font-size:0.7rem;' +
            'background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);' +
            'border-radius:4px;color:#9498a8;cursor:pointer;font-family:inherit;z-index:1;' +
            'transition:all 150ms ease;';

        btn.addEventListener('mouseenter', function () {
            btn.style.background = 'rgba(255,255,255,0.1)';
            btn.style.color = '#e4e6ed';
        });
        btn.addEventListener('mouseleave', function () {
            btn.style.background = 'rgba(255,255,255,0.06)';
            btn.style.color = '#9498a8';
        });

        btn.addEventListener('click', function () {
            var code = block.querySelector('code');
            var text = code ? code.textContent : block.textContent;
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied';
                btn.style.color = '#22c55e';
                setTimeout(function () {
                    btn.textContent = 'Copy';
                    btn.style.color = '#9498a8';
                }, 1500);
            });
        });

        block.style.position = 'relative';
        block.appendChild(btn);
    });

    // ── Smooth anchor scrolling ──
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                var offset = target.offsetTop - 80;
                window.scrollTo({ top: offset, behavior: 'smooth' });
            }
        });
    });

    // ── Header scroll effect ──
    var header = document.querySelector('.header');
    if (header) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 50) {
                header.style.borderBottomColor = 'rgba(30, 32, 48, 0.8)';
                header.style.background = 'rgba(8, 9, 13, 0.95)';
            } else {
                header.style.borderBottomColor = '';
                header.style.background = '';
            }
        });
    }

});
