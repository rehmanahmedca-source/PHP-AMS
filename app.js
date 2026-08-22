(() => {
  const AMS = window.AMS || { clients: [], materials: [], suppliers: [], accounts: [], deliveries: [], lenders: [], bookings: [] };

  const sources = {
    clients: () => AMS.clients || [],
    materials: () => AMS.materials || [],
    suppliers: () => AMS.suppliers || [],
    accounts: () => AMS.accounts || [],
    deliveries: () => AMS.deliveries || [],
    lenders: () => AMS.lenders || [],
    bookings: () => (AMS.bookings || []).filter(b => !currentClientId() || String(b.client_id) === String(currentClientId())),
    parties() {
      const type = document.querySelector('[name="party_type"]')?.value || 'client';
      if (type === 'supplier') return AMS.suppliers || [];
      if (type === 'delivery_person') return AMS.deliveries || [];
      if (type === 'lender') return AMS.lenders || [];
      return AMS.clients || [];
    }
  };

  function currentClientId() {
    return document.querySelector('[name="client_id"]')?.value || '';
  }

  function labelFor(item, source) {
    if (!item) return '';
    if (source === 'materials') return `${item.name} (${item.unit || ''})`.trim();
    if (source === 'bookings') return `${item.auto_bill_no} · ${item.manual_bill_no || ''}`.trim();
    return item.name || item.label || '';
  }

  function subFor(item, source) {
    if (!item) return '';
    if (source === 'clients' || source === 'parties') return [item.code, item.phone, item.default_type].filter(Boolean).join(' · ');
    if (source === 'materials') return [item.code, item.category, item.stock != null ? `Stock ${item.stock}` : ''].filter(Boolean).join(' · ');
    if (source === 'accounts') return [item.account_type, item.balance != null ? money(item.balance) : ''].filter(Boolean).join(' · ');
    if (source === 'bookings') return [item.client_name, item.status, item.pending ? `${item.pending} pending` : ''].filter(Boolean).join(' · ');
    return item.code || item.phone || '';
  }

  function metaFor(item, source) {
    if (source === 'materials' && item.rate) return money(item.rate);
    if ((source === 'clients' || source === 'parties') && item.balance) return money(item.balance);
    return '';
  }

  function money(n) {
    const v = Number(n || 0);
    return 'Rs ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function findItem(source, id) {
    return (sources[source] ? sources[source]() : []).find(x => String(x.id) === String(id));
  }

  function filterItems(source, q) {
    const list = sources[source] ? sources[source]() : [];
    const s = (q || '').trim().toLowerCase();
    if (!s) return list.slice(0, 80);
    return list.filter(item => {
      const blob = [item.name, item.code, item.phone, item.auto_bill_no, item.manual_bill_no, item.unit, item.category]
        .filter(Boolean).join(' ').toLowerCase();
      return s.split(/\s+/).every(part => blob.includes(part));
    }).slice(0, 80);
  }

  function bindCombo(root) {
    if (root.dataset.bound) return;
    root.dataset.bound = '1';
    const source = root.dataset.source;
    const hidden = root.querySelector('.combo-value');
    const input = root.querySelector('.combo-input');
    const list = root.querySelector('.combo-list');
    const clear = root.querySelector('.combo-clear');
    let active = -1;
    let shown = [];

    const syncText = () => {
      const item = findItem(source, hidden.value);
      input.value = item ? labelFor(item, source) : (hidden.value ? input.value : '');
      root.classList.toggle('has-value', Boolean(hidden.value));
    };

    const render = (q) => {
      shown = filterItems(source, q);
      if (!shown.length) {
        list.innerHTML = `<div class="combo-empty">No matches for “${escapeHtml(q || '')}”</div>`;
        return;
      }
      list.innerHTML = shown.map((item, i) => `
        <div class="combo-item${i === active ? ' active' : ''}" data-id="${item.id}" role="option">
          <span><b>${escapeHtml(labelFor(item, source))}</b><small>${escapeHtml(subFor(item, source))}</small></span>
          <span class="meta">${escapeHtml(metaFor(item, source))}</span>
        </div>`).join('');
    };

    const open = () => { root.classList.add('open'); active = -1; render(input.value && hidden.value ? '' : input.value); };
    const close = () => root.classList.remove('open');

    const choose = (id) => {
      hidden.value = id || '';
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
      syncText();
      close();
    };

    input.addEventListener('focus', open);
    input.addEventListener('input', () => {
      if (hidden.value) {
        hidden.value = '';
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        root.classList.remove('has-value');
      }
      open();
      render(input.value);
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, shown.length - 1); render(hidden.value ? '' : input.value); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(hidden.value ? '' : input.value); }
      else if (e.key === 'Enter' && root.classList.contains('open') && shown[active]) { e.preventDefault(); choose(shown[active].id); }
      else if (e.key === 'Escape') close();
    });
    list.addEventListener('mousedown', (e) => {
      const item = e.target.closest('.combo-item');
      if (item) choose(item.dataset.id);
    });
    clear?.addEventListener('click', () => { input.value = ''; choose(''); input.focus(); });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) close(); });
    syncText();
    root._sync = syncText;
    root._choose = choose;
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function initCombos(scope = document) {
    scope.querySelectorAll('.combo[data-source]').forEach(bindCombo);
  }

  function lineTemplate(index, item = {}) {
    return `
      <tr class="line-row">
        <td>
          <div class="combo" data-source="materials">
            <input type="hidden" name="items[${index}][material_id]" value="${item.material_id || ''}" class="combo-value line-material" required>
            <input type="text" class="combo-input" placeholder="Search material…" autocomplete="off" spellcheck="false">
            <button type="button" class="combo-clear" tabindex="-1" aria-label="Clear">×</button>
            <div class="combo-list" role="listbox"></div>
          </div>
        </td>
        <td><input type="number" step="0.01" min="0.01" name="items[${index}][qty]" class="line-qty" value="${item.qty || ''}" required></td>
        <td><input type="number" step="0.01" min="0" name="items[${index}][rate]" class="line-rate" value="${item.rate || ''}" required></td>
        <td class="amt-cell line-amt">Rs 0.00</td>
        <td><button type="button" class="line-remove" title="Remove">✕</button></td>
      </tr>`;
  }

  function recalcLines() {
    let subtotal = 0;
    document.querySelectorAll('.line-row').forEach(row => {
      const qty = Number(row.querySelector('.line-qty')?.value || 0);
      const rate = Number(row.querySelector('.line-rate')?.value || 0);
      const amt = qty * rate;
      subtotal += amt;
      const cell = row.querySelector('.line-amt');
      if (cell) cell.textContent = money(amt);
    });
    const discount = Number(document.querySelector('[name="discount"]')?.value || 0);
    const extra = ['loading_cost', 'freight_cost', 'other_expense']
      .reduce((s, n) => s + Number(document.querySelector(`[name="${n}"]`)?.value || 0), 0);
    const total = Math.max(0, subtotal + extra - discount);
    const subEl = document.getElementById('sum-subtotal');
    const totEl = document.getElementById('sum-total');
    if (subEl) subEl.textContent = money(subtotal);
    if (totEl) totEl.textContent = money(total);
  }

  function bindLineRow(row) {
    initCombos(row);
    const material = row.querySelector('.line-material');
    material?.addEventListener('change', () => {
      const item = findItem('materials', material.value);
      const rate = row.querySelector('.line-rate');
      if (item && rate && (!rate.value || Number(rate.value) === 0)) {
        rate.value = item.rate || 0;
      }
      recalcLines();
    });
    row.querySelectorAll('.line-qty, .line-rate').forEach(el => el.addEventListener('input', recalcLines));
    row.querySelector('.line-remove')?.addEventListener('click', () => {
      const body = row.parentElement;
      if (body.querySelectorAll('.line-row').length === 1) {
        row.querySelectorAll('input').forEach(i => { if (i.classList.contains('combo-input')) i.value = ''; else i.value = ''; });
        row.querySelector('.combo-value').value = '';
        recalcLines();
        return;
      }
      row.remove();
      recalcLines();
    });
  }

  function initLines() {
    const body = document.getElementById('line-body');
    const add = document.getElementById('add-line');
    if (!body) return;
    let idx = body.querySelectorAll('.line-row').length;
    if (!idx) {
      body.insertAdjacentHTML('beforeend', lineTemplate(0));
      idx = 1;
    }
    body.querySelectorAll('.line-row').forEach(bindLineRow);
    add?.addEventListener('click', () => {
      body.insertAdjacentHTML('beforeend', lineTemplate(idx++));
      bindLineRow(body.lastElementChild);
      body.lastElementChild.querySelector('.combo-input')?.focus();
    });
    document.querySelectorAll('[name="discount"], [name="loading_cost"], [name="freight_cost"], [name="other_expense"]')
      .forEach(el => el.addEventListener('input', recalcLines));
    recalcLines();
  }

  function loadBooking() {
    const result = document.getElementById('booking-result');
    const empty = document.getElementById('booking-empty');
    const bookingId = document.querySelector('[name="booking_id"]')?.value;
    const clientId = document.querySelector('[name="client_id"]')?.value;
    if (!result) return;
    if (!bookingId) {
      result.innerHTML = '';
      if (empty) empty.style.display = 'block';
      return;
    }
    fetch(`?ajax=booking_info&booking_id=${encodeURIComponent(bookingId)}&client_id=${encodeURIComponent(clientId || '')}`)
      .then(r => r.json())
      .then(d => {
        if (empty) empty.style.display = 'none';
        if (!d.booking) {
          result.innerHTML = '<div class="notice danger">This booking does not belong to the selected client.</div>';
          return;
        }
        let html = `<div class="ledger-strip"><span><b>${escapeHtml(d.booking.auto_bill_no)}</b><small>${escapeHtml(d.booking.client_name)} · ${escapeHtml(d.booking.status)}</small></span><strong>${money(d.balance)}<small>client ledger</small></strong></div><div class="booking-table"><div class="booking-head"><span>Material</span><span>Booked</span><span>Dispatched</span><span>Remaining</span><span>Value</span></div>`;
        (d.items || []).forEach(x => {
          html += `<div class="booking-row"><span><b>${escapeHtml(x.material_name)}</b><small>Rate ${Number(x.rate).toLocaleString()}</small></span><span>${x.qty_booked}</span><span>${x.qty_dispatched}</span><span class="remaining">${x.qty_pending}</span><span>${money(x.qty_pending * x.rate)}</span></div>`;
        });
        result.innerHTML = html + '</div>';
      })
      .catch(() => { result.innerHTML = '<div class="notice danger">Unable to load booking details.</div>'; });
  }

  function refreshClientMeta() {
    const box = document.getElementById('client-meta');
    if (!box) return;
    const id = currentClientId();
    const c = findItem('clients', id);
    if (!c) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    box.classList.remove('hidden');
    const bal = Number(c.balance || 0);
    box.innerHTML = `<span>Code <b>${escapeHtml(c.code || '—')}</b></span>
      <span>Default <b>${escapeHtml(c.default_type || 'cash')}</b></span>
      <span class="${bal > 0 ? 'owed' : ''}">Balance <b>${money(bal)}</b></span>
      ${c.phone ? `<span>Phone <b>${escapeHtml(c.phone)}</b></span>` : ''}`;
  }

  function toggleSaleType() {
    const type = document.querySelector('[name="sale_type"]')?.value || '';
    const bookingWrap = document.getElementById('booking-wrap');
    const bookingPanel = document.getElementById('booking-panel');
    const show = type === 'booking' || type === 'booking_credit';
    bookingWrap?.classList.toggle('hidden', !show);
    bookingPanel?.classList.toggle('hidden', !show);
    if (!show) {
      const hidden = document.querySelector('[name="booking_id"]');
      if (hidden) hidden.value = '';
    }
  }

  function togglePaymentMode() {
    const mode = document.querySelector('[name="payment_mode"], [name="payment_method"], [name="payment_type"]')?.value;
    const acc = document.getElementById('account-wrap');
    if (acc) acc.classList.toggle('hidden', mode === 'credit' || mode === 'adjustment');
  }

  function onPartyTypeChange() {
    const combo = document.querySelector('.combo[data-source="parties"]');
    if (!combo) return;
    combo.querySelector('.combo-value').value = '';
    combo.querySelector('.combo-input').value = '';
    combo.classList.remove('has-value');
  }

  document.addEventListener('DOMContentLoaded', () => {
    initCombos();
    initLines();
    toggleSaleType();
    togglePaymentMode();
    refreshClientMeta();

    document.querySelector('[name="sale_type"]')?.addEventListener('change', toggleSaleType);
    document.querySelector('[name="payment_mode"]')?.addEventListener('change', togglePaymentMode);
    document.querySelector('[name="payment_method"]')?.addEventListener('change', togglePaymentMode);
    document.querySelector('[name="payment_type"]')?.addEventListener('change', togglePaymentMode);
    document.querySelector('[name="party_type"]')?.addEventListener('change', onPartyTypeChange);
    document.querySelector('[name="client_id"]')?.addEventListener('change', () => {
      refreshClientMeta();
      const booking = document.querySelector('.combo[data-source="bookings"]');
      if (booking) {
        booking.querySelector('.combo-value').value = '';
        booking.querySelector('.combo-input').value = '';
        booking._sync?.();
      }
      loadBooking();
    });
    document.querySelector('[name="booking_id"]')?.addEventListener('change', loadBooking);
    if (document.querySelector('[name="booking_id"]')?.value) loadBooking();

    document.getElementById('txn-form')?.addEventListener('submit', (e) => {
      const required = e.target.querySelectorAll('.combo-value[required]');
      for (const el of required) {
        if (!el.value) {
          e.preventDefault();
          el.closest('.combo')?.querySelector('.combo-input')?.focus();
          alert('Please choose a value from the searchable list.');
          return;
        }
      }
    });
  });
})();
