(function () {
  'use strict';

  var form = document.querySelector('.lform');
  var msg  = form && form.querySelector('.lform__msg');
  var btn  = form && form.querySelector('.send');

  function setMsg(text, bad) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.classList.toggle('is-bad', !!bad);
  }

  function markFields(errors) {
    form.querySelectorAll('.field').forEach(function (f) { f.classList.remove('is-bad'); });
    Object.keys(errors || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (input && input.parentElement) input.parentElement.classList.add('is-bad');
    });
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      setMsg('');
      markFields({});
      btn.disabled = true;

      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
        .then(function (r) {
          if (r.ok && r.body.ok) {
            form.reset();
            setMsg(r.body.message || 'תודה! נחזור אליכם בהקדם.', false);
            return;
          }
          markFields(r.body.errors);
          var first = r.body.errors && Object.keys(r.body.errors)[0];
          setMsg((first && r.body.errors[first]) || r.body.error || 'השליחה נכשלה, נסו שוב.', true);
        })
        .catch(function () { setMsg('אין חיבור לשרת, נסו שוב בעוד רגע.', true); })
        .finally(function () { btn.disabled = false; });
    });
  }

  // Step 2 of the page asks buyers to quote a SKU, so a click on a product
  // carries it into the form for them.
  document.querySelectorAll('.card').forEach(function (card) {
    var sku = card.querySelector('.card__sku');
    if (!sku || !sku.textContent.trim()) return;
    card.classList.add('is-pickable');
    card.setAttribute('role', 'button');
    card.setAttribute('tabindex', '0');
    card.setAttribute('title', 'בחרו מוצר זה והמק״ט יועתק לטופס');

    function pick() {
      var field = document.querySelector('[name="sku"]');
      if (!field) return;
      field.value = sku.textContent.trim();
      document.querySelector('.foot').scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(function () { field.focus({ preventScroll: true }); }, 450);
      setMsg('נבחר מק״ט ' + field.value + ' — השלימו פרטים ונחזור אליכם.', false);
    }
    card.addEventListener('click', pick);
    card.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); pick(); }
    });
  });

  var chevron = document.querySelector('.lead__chevron');
  if (chevron) {
    chevron.style.cursor = 'pointer';
    chevron.addEventListener('click', function () {
      document.querySelector('.foot').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }
}());
