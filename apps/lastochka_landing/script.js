/* Ласточка Джами landing — vanilla JS */

(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Sticky nav scroll state ---- */
  const nav = document.getElementById('nav');
  const onScroll = () => {
    if (window.scrollY > 8) nav.classList.add('is-scrolled');
    else nav.classList.remove('is-scrolled');
  };
  document.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---- «ссылкам»: магазин приложений под платформу телефона ---- */
  const storeLink = document.querySelector('[data-store-link]');
  if (storeLink) {
    const ua = navigator.userAgent || '';
    const isIOS = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    let store = '';
    if (isIOS) store = '.store-btn--apple';
    else if (/HUAWEI|HarmonyOS|HMSCore/i.test(ua)) store = '.store-btn--huawei';
    else if (/Android/i.test(ua)) store = '.store-btn--google';
    const btn = store && document.querySelector('.hero__download ' + store);
    if (btn) {
      storeLink.href = btn.href;
      storeLink.target = '_blank';
      storeLink.rel = 'noopener';
    }
  }

  /* ---- Burger menu ---- */
  const burger = document.querySelector('.nav__burger');
  const panel = document.querySelector('.nav__panel');
  if (burger && panel) {
    burger.addEventListener('click', () => {
      const open = burger.getAttribute('aria-expanded') === 'true';
      burger.setAttribute('aria-expanded', String(!open));
      panel.hidden = open;
      panel.classList.toggle('is-open', !open);
      document.body.style.overflow = open ? '' : 'hidden';
    });
    panel.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        burger.setAttribute('aria-expanded', 'false');
        panel.hidden = true;
        panel.classList.remove('is-open');
        document.body.style.overflow = '';
      });
    });
  }

  /* ---- Reveal-on-scroll ---- */
  if (!reduceMotion && 'IntersectionObserver' in window) {
    const reveals = document.querySelectorAll('.reveal');
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
          // Stagger siblings within parent
          const idx = Array.from(entry.target.parentNode.children).indexOf(entry.target);
          entry.target.style.transitionDelay = `${Math.min(idx * 60, 300)}ms`;
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach(el => io.observe(el));
  } else {
    document.querySelectorAll('.reveal').forEach(el => el.classList.add('is-visible'));
  }

  /* ---- Smooth anchor scroll respecting reduce-motion ---- */
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const href = link.getAttribute('href');
      if (!href || href === '#') return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      const top = target.getBoundingClientRect().top + window.scrollY - 70;
      window.scrollTo({ top, behavior: reduceMotion ? 'auto' : 'smooth' });
      history.pushState(null, '', href);
    });
  });

  /* ---- Magnet effect on big buttons ---- */
  if (!reduceMotion && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    document.querySelectorAll('.store-btn, .btn--bright').forEach(el => {
      el.addEventListener('mousemove', (e) => {
        const r = el.getBoundingClientRect();
        const x = e.clientX - r.left - r.width / 2;
        const y = e.clientY - r.top - r.height / 2;
        el.style.transform = `translate(${x * 0.08}px, ${y * 0.12}px)`;
      });
      el.addEventListener('mouseleave', () => {
        el.style.transform = '';
      });
    });
  }

  /* ---- FAQ — close others on open ---- */
  const faqItems = document.querySelectorAll('.faq__item');
  faqItems.forEach(item => {
    item.addEventListener('toggle', () => {
      if (item.open) {
        faqItems.forEach(other => {
          if (other !== item && other.open) other.open = false;
        });
      }
    });
  });

  /* ---- Cookie banner ---- */
  const COOKIE_KEY = 'lastochka_cookie_accepted_v1';
  const cookie = document.querySelector('.cookie');
  if (cookie) {
    let alreadyAccepted = false;
    try { alreadyAccepted = !!localStorage.getItem(COOKIE_KEY); } catch (e) {}
    if (!alreadyAccepted) {
      setTimeout(() => { cookie.hidden = false; }, 1200);
    }
    // Delegate via the cookie container — robust to re-renders / event-stop chains
    cookie.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      try { localStorage.setItem(COOKIE_KEY, '1'); } catch (err) {}
      cookie.hidden = true;
      cookie.style.display = 'none';
      // belt + suspenders: remove from DOM after fade
      setTimeout(() => { cookie.remove(); }, 50);
    });
  }

  /* ---- Screens gallery — horizontal scroll buttons ---- */
  const rail = document.getElementById('screens-rail');
  const screensNav = document.querySelectorAll('.screens__btn');
  if (rail && screensNav.length) {
    const item = rail.querySelector('.screens__item');
    const step = () => {
      const it = rail.querySelector('.screens__item');
      if (!it) return 280;
      const rect = it.getBoundingClientRect();
      const gap = parseFloat(getComputedStyle(rail.querySelector('.screens__track')).gap) || 16;
      return rect.width + gap;
    };
    const updateBtns = () => {
      const max = rail.scrollWidth - rail.clientWidth - 1;
      screensNav.forEach(btn => {
        const dir = btn.dataset.dir;
        if (dir === 'prev') btn.disabled = rail.scrollLeft <= 1;
        if (dir === 'next') btn.disabled = rail.scrollLeft >= max;
      });
    };
    screensNav.forEach(btn => {
      btn.addEventListener('click', () => {
        const dir = btn.dataset.dir === 'next' ? 1 : -1;
        rail.scrollBy({ left: step() * dir, behavior: reduceMotion ? 'auto' : 'smooth' });
      });
    });
    rail.addEventListener('scroll', () => {
      window.requestAnimationFrame(updateBtns);
    }, { passive: true });
    updateBtns();
    // Keyboard support on rail
    rail.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { rail.scrollBy({ left: step(), behavior: 'smooth' }); e.preventDefault(); }
      if (e.key === 'ArrowLeft')  { rail.scrollBy({ left: -step(), behavior: 'smooth' }); e.preventDefault(); }
    });
  }

  /* ---- Year (if needed elsewhere) ---- */
  const y = new Date().getFullYear();
  document.querySelectorAll('[data-year]').forEach(el => { el.textContent = y; });

})();
