{{-- Info-bulle des graphiques : éléments .hit avec data-tip (survol, focus clavier ou toucher). --}}
<script>
(() => {
  document.querySelectorAll('.chart-box').forEach((box) => {
    const tip = document.createElement('div');
    tip.className = 'chart-tip';
    tip.hidden = true;
    box.appendChild(tip);

    const show = (el) => {
      const r = el.getBoundingClientRect();
      const b = box.getBoundingClientRect();
      tip.textContent = el.dataset.tip;
      tip.style.left = (r.left + r.width / 2 - b.left) + 'px';
      tip.style.top = (el.tagName === 'rect' ? box.querySelector('svg').getBoundingClientRect().top - b.top + 40 : r.top - b.top + r.height / 2) + 'px';
      tip.hidden = false;
    };

    box.querySelectorAll('.hit').forEach((el) => {
      el.setAttribute('tabindex', '0');
      el.setAttribute('aria-label', el.dataset.tip);
      el.addEventListener('mouseenter', () => show(el));
      el.addEventListener('focus', () => show(el));
      el.addEventListener('touchstart', () => show(el), { passive: true });
      el.addEventListener('mouseleave', () => { tip.hidden = true; });
      el.addEventListener('blur', () => { tip.hidden = true; });
    });
  });
})();
</script>
