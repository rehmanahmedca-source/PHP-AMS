document.addEventListener('DOMContentLoaded', () => {
  const client = document.getElementById('field_client_id');
  const booking = document.getElementById('field_booking_id');
  const result = document.getElementById('booking-result');
  const empty = document.getElementById('booking-empty');
  if (!client || !booking || !result) return;
  const load = () => {
    const id = booking.value;
    if (!id) { result.innerHTML = ''; empty.style.display = 'block'; return; }
    fetch(`?ajax=booking_info&booking_id=${encodeURIComponent(id)}&client_id=${encodeURIComponent(client.value)}`)
      .then(r => r.json()).then(d => {
        empty.style.display = 'none';
        if (!d.booking) { result.innerHTML = '<div class="notice danger">This booking does not belong to the selected client.</div>'; return; }
        let html = `<div class="ledger-strip"><span><b>${d.booking.auto_bill_no}</b><small>${d.booking.client_name} · ${d.booking.status}</small></span><strong>Payable ${Number(d.balance).toLocaleString(undefined,{minimumFractionDigits:2})}<small>client ledger</small></strong></div><div class="booking-table"><div class="booking-head"><span>Material</span><span>Booked</span><span>Dispatched</span><span>Remaining</span><span>Value</span></div>`;
        d.items.forEach(x => { html += `<div class="booking-row"><span><b>${x.material_name}</b><small>Rate ${Number(x.rate).toLocaleString()}</small></span><span>${x.qty_booked}</span><span>${x.qty_dispatched}</span><span class="remaining">${x.qty_pending}</span><span>${Number(x.qty_pending*x.rate).toLocaleString(undefined,{minimumFractionDigits:2})}</span></div>`; });
        result.innerHTML = html + '</div>';
      }).catch(() => { result.innerHTML = '<div class="notice danger">Unable to load booking details.</div>'; });
  };
  client.addEventListener('change', () => {
    [...booking.options].forEach(o => { if (o.value) o.hidden = o.dataset.client !== client.value; });
    if (booking.selectedOptions[0]?.hidden) booking.value = '';
    load();
  });
  booking.addEventListener('change', load);
});