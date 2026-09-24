(function () {
  'use strict';

  var pm = document.getElementById('product-modal');
  var cards = [].slice.call(document.querySelectorAll('.card'));

  // A plain left click is ours; ctrl/cmd/shift-click and the middle button stay
  // the browser's, because every card is a real link to its own address.
  function plain(ev) {
    return ev.button === 0 && !ev.metaKey && !ev.ctrlKey && !ev.shiftKey && !ev.altKey;
  }

  // Without <dialog> support the card is left as the plain link it already is:
  // the product's own address renders the same details on the server.
  if (!pm || typeof pm.showModal !== 'function') {
    return;
  }

  var lastCard = null;
  var syncing = false;          // true while the history drives the dialog

  function money(n) { return '₪' + n; }

  function cardFor(slug) {
    if (!slug) return null;
    for (var i = 0; i < cards.length; i++) {
      if (cards[i].dataset.slug === slug) return cards[i];
    }
    return null;
  }

  function slugInUrl() {
    return new URLSearchParams(location.search).get('p');
  }

  function fill(card) {
    var d = card.dataset;
    var before = parseInt(d.before, 10);
    var after = parseInt(d.after, 10);

    pm.querySelector('.pm__name').textContent = d.name || '';
    pm.querySelector('.pm__sku').textContent = d.sku ? 'מק״ט ' + d.sku : '';

    var note = pm.querySelector('.pm__note');
    note.textContent = d.note || '';
    note.hidden = !d.note;

    var img = pm.querySelector('.pm__img');
    img.src = d.img || '';
    img.alt = (d.name || '') + (d.sku ? ' ' + d.sku : '');
    img.closest('.pm__media').hidden = !d.img;

    // The maker's mark travels beside the photo rather than inside it.
    var brand = pm.querySelector('.pm__brand');
    brand.hidden = !d.brand;
    if (d.brand) brand.src = d.brand;

    var oldEl = pm.querySelector('.pm__old');
    oldEl.hidden = !before;
    if (before) oldEl.querySelector('span').textContent = before;

    var newEl = pm.querySelector('.pm__new');
    newEl.hidden = !after;
    if (after) newEl.querySelector('span').textContent = after;

    var save = pm.querySelector('.pm__save');
    save.hidden = !(before && after && before > after);
    if (!save.hidden) {
      save.textContent = 'חיסכון ' + money(before - after) + ' · ' +
        Math.round((before - after) / before * 100) + '%';
    }

    var desc = pm.querySelector('.pm__desc');
    desc.textContent = d.desc || '';
    desc.hidden = !d.desc;

    // Each product carries its own link, with its name and SKU already in the
    // message, so the person sending it types nothing.
    pm.querySelector('.wa--pm').href = d.wa || '';
    pm.querySelector('.pm__fine').textContent =
      (d.sku ? 'המק״ט ' + d.sku + ' מצורף להודעה · ' : '') + 'אין חיוב ואין רכישה באתר';
  }

  // One count per product per browser session: enough to tell which products
  // draw people in, without a visitor who reopens the same one inflating it.
  function countView(card) {
    var slug = card.dataset.slug;
    if (!slug) return;
    try {
      var seen = (sessionStorage.getItem('pv') || '').split(',');
      if (seen.indexOf(slug) !== -1) return;
      seen.push(slug);
      sessionStorage.setItem('pv', seen.join(','));
    } catch (e) { /* private mode: count it rather than lose it */ }

    var body = new FormData();
    body.append('p', slug);
    if (navigator.sendBeacon) navigator.sendBeacon('api/view.php', body);
    else fetch('api/view.php', { method: 'POST', body: body, keepalive: true }).catch(function () {});
  }

  function open(card, push) {
    lastCard = card;
    countView(card);
    fill(card);
    if (!pm.open) pm.showModal();
    document.documentElement.classList.add('is-locked');
    pm.querySelector('.pm__body').scrollTop = 0;
    // Each product has an address of its own; opening one goes to it.
    if (push) history.pushState({ pm: 1 }, '', card.getAttribute('href'));
  }

  function close() {
    if (pm.open) pm.close();
  }

  pm.addEventListener('close', function () {
    document.documentElement.classList.remove('is-locked');
    // Put the caret back where the visitor left off in the gallery.
    if (lastCard) lastCard.focus({ preventScroll: true });
    if (syncing) return;
    if (history.state && history.state.pm) {
      history.back();                       // undo the entry opening it added
    } else if (slugInUrl()) {
      // Landed straight on a product address: drop the query, add no history.
      history.replaceState({}, '', location.pathname);
    }
  });

  window.addEventListener('popstate', function () {
    syncing = true;
    var card = cardFor(slugInUrl());
    if (card) { open(card, false); } else { close(); }
    syncing = false;
  });

  pm.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', close);
  });

  // A click that lands on the dialog element itself is a click on the backdrop.
  pm.addEventListener('click', function (ev) {
    if (ev.target === pm) close();
  });

  cards.forEach(function (card) {
    card.addEventListener('click', function (ev) {
      if (!plain(ev)) return;
      ev.preventDefault();
      open(card, true);
    });
  });

  // Arriving on a product address: the server already rendered its details, so
  // this only has to raise the pop-up over them.
  if (pm.dataset.open) {
    var landed = cardFor(slugInUrl());
    if (landed) open(landed, false);
  }
}());
