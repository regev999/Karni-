(function () {
  'use strict';

  /* ---------------------------------------------------------------- forms -- */

  function msgEl(form) { return form.querySelector('.lform__msg'); }

  function setMsg(form, text, bad) {
    var el = msgEl(form);
    if (!el) return;
    el.textContent = text || '';
    el.classList.toggle('is-bad', !!bad);
  }

  function markFields(form, errors) {
    form.querySelectorAll('.field, .pf').forEach(function (f) { f.classList.remove('is-bad'); });
    Object.keys(errors || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (input && input.parentElement) input.parentElement.classList.add('is-bad');
    });
  }

  // Both the footer form and the pop-up form post to the same endpoint.
  function bindLeadForm(form, onSuccess) {
    var btn = form.querySelector('button[type=submit]');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      setMsg(form, '');
      markFields(form, {});
      if (btn) btn.disabled = true;

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json' }
      })
        .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
        .then(function (r) {
          if (r.ok && r.body.ok) {
            form.reset();
            if (onSuccess) onSuccess(r.body);
            else setMsg(form, r.body.message || 'תודה! נחזור אליכם בהקדם.', false);
            return;
          }
          markFields(form, r.body.errors);
          var first = r.body.errors && Object.keys(r.body.errors)[0];
          setMsg(form, (first && r.body.errors[first]) || r.body.error || 'השליחה נכשלה, נסו שוב.', true);
        })
        .catch(function () { setMsg(form, 'אין חיבור לשרת, נסו שוב בעוד רגע.', true); })
        .finally(function () { if (btn) btn.disabled = false; });
    });
  }

  var footerForm = document.querySelector('.foot .lform');
  if (footerForm) bindLeadForm(footerForm);

  /* ------------------------------------------------------------- pop-up --- */

  var pm = document.getElementById('product-modal');
  var cards = [].slice.call(document.querySelectorAll('.card'));

  // A plain left click is ours; ctrl/cmd/shift-click and the middle button stay
  // the browser's, because every card is a real link to its own address.
  function plain(ev) {
    return ev.button === 0 && !ev.metaKey && !ev.ctrlKey && !ev.shiftKey && !ev.altKey;
  }

  // Without <dialog> support, fall back to carrying the SKU into the footer form.
  if (!pm || typeof pm.showModal !== 'function') {
    cards.forEach(function (card) {
      card.addEventListener('click', function (ev) {
        if (!plain(ev)) return;
        var field = document.querySelector('.foot [name=sku]');
        if (!field) return;
        ev.preventDefault();
        field.value = card.dataset.sku || '';
        document.querySelector('.foot').scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function () { field.focus({ preventScroll: true }); }, 450);
      });
    });
    return;
  }

  var pmForm = pm.querySelector('.lform');
  var ask = pm.querySelector('.pm__ask');
  var done = pm.querySelector('.pm__done');
  var lastCard = null;

  function money(n) { return '₪' + n; }

  function fill(card) {
    var d = card.dataset;
    var before = parseInt(d.before, 10);
    var after = parseInt(d.after, 10);

    pm.querySelector('.pm__name').textContent = d.name || '';
    pm.querySelector('.pm__sku').textContent = d.sku ? 'מק״ט ' + d.sku : '';

    var img = pm.querySelector('.pm__img');
    img.src = d.img || '';
    img.alt = (d.name || '') + (d.sku ? ' ' + d.sku : '');
    img.closest('.pm__media').hidden = !d.img;

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

    pmForm.querySelector('[name=sku]').value = d.sku || '';
    pmForm.querySelector('[name=product]').value = d.name || '';
    pm.querySelector('.pm__fine').textContent = (d.sku ? 'המק״ט ' + d.sku + ' מצורף לפנייה אוטומטית · ' : '')
      + 'אין חיוב ואין רכישה באתר';
    pm.querySelector('.pm__done-s').textContent = 'נציג יחזור אליכם בהקדם'
      + (d.sku ? ' בנוגע למק״ט ' + d.sku : '') + '.';
  }

  // True while the history drives the dialog, so the two do not answer each other.
  var syncing = false;

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

  function open(card, push) {
    lastCard = card;
    fill(card);
    ask.hidden = false;
    done.hidden = true;
    setMsg(pmForm, '');
    markFields(pmForm, {});
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

  bindLeadForm(pmForm, function () {
    ask.hidden = true;
    done.hidden = false;
    pm.querySelector('.pm__body').scrollTop = 0;
    pm.querySelector('.pm__ghost').focus();
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
