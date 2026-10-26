document.querySelectorAll('[data-menu-toggle]').forEach(button=>button.addEventListener('click',()=>{let nav=document.getElementById('mobileNav');let open=nav.classList.toggle('open');button.setAttribute('aria-expanded',String(open))}));document.querySelectorAll('[data-year]').forEach(e=>e.textContent=new Date().getFullYear());document.querySelectorAll('[data-reveal]').forEach(e=>{if(!('IntersectionObserver'in window)){e.classList.add('visible');return}let o=new IntersectionObserver(entries=>{entries.forEach(x=>{if(x.isIntersecting){x.target.classList.add('visible');o.unobserve(x.target)}})});o.observe(e)});

/* Hosted PayFast checkout: the selected package is signed on the server.
   Only the browser POSTs the signed fields to PayFast. No internal payment UI. */
document.querySelectorAll('form[data-payfast-handoff]').forEach(form => {
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (!button || button.disabled || form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';
    button.disabled = true;
    const oldText = button.textContent;
    button.textContent = 'Opening PayFast…';
    const existing = form.querySelector('[role="alert"]');
    if (existing) existing.remove();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        redirect: 'error',
        signal: controller.signal,
        headers: {'X-FyndEt-Checkout': 'direct', 'Accept': 'application/json'}
      });
      if (!response.ok) throw new Error('Checkout request failed (HTTP ' + response.status + ')');
      const result = await response.json();
      const destinations = ['https://www.payfast.co.za/eng/process', 'https://sandbox.payfast.co.za/eng/process'];
      if (!result || !destinations.includes(result.gateway) || !result.fields ||
          typeof result.fields !== 'object' || Array.isArray(result.fields) ||
          !result.fields.signature || !result.fields.m_payment_id) {
        throw new Error('Invalid signed checkout response');
      }
      const paymentForm = document.createElement('form');
      paymentForm.method = 'POST';
      paymentForm.action = result.gateway;
      paymentForm.acceptCharset = 'UTF-8';
      paymentForm.style.display = 'none';
      for (const [name, value] of Object.entries(result.fields)) {
        if (typeof value !== 'string' && typeof value !== 'number') throw new Error('Invalid payment field');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        paymentForm.appendChild(input);
      }
      document.body.appendChild(paymentForm);
      HTMLFormElement.prototype.submit.call(paymentForm);
    } catch (error) {
      form.dataset.submitting = '0';
      button.disabled = false;
      button.textContent = oldText;
      const note = document.createElement('p');
      note.setAttribute('role', 'alert');
      note.textContent = 'PayFast checkout could not start (' + (error && error.name === 'AbortError' ? 'the checkout server did not respond within 15 seconds' : error instanceof Error ? error.message : 'unknown error') + '). No payment was made.';
      form.appendChild(note);
      console.error('FyndEt payment handoff:', error);
    } finally {
      clearTimeout(timeout);
    }
  });
});
