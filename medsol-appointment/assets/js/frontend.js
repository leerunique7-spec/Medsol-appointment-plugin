document.addEventListener('DOMContentLoaded', () => {
  if (typeof medsolFrontend === 'undefined') return;

  const nonce  = medsolFrontend.nonce;
  const ajaxUrl = medsolFrontend.ajax_url;

  // Scope all queries to the form to avoid collisions
  const form = document.getElementById('medsol-booking-form');
  if (!form) return;

  const q  = (sel) => form.querySelector(sel);

  // Full-form selects (may be null on partial form)
  const locationSelect  = q('#location_id-select');
  const serviceSelect   = q('#service_id-select');
  const employeeSelect  = q('#employee_id-select');

  // Hidden inputs (present on partial form)
  const locationHidden  = q('input[name="location_id"]');
  const serviceHidden   = q('input[name="service_id"]');
  const employeeHidden  = q('input[name="employee_id"]');

  const calendarContainer = q('#booking-calendar-container');
  const selectedDateInput = q('#selected-date');
  const timeSlotsDiv      = q('#time-slots'); // may be absent early
  const submitBtn         = q('#submit-booking');
  const messageDiv        = document.getElementById('booking-message');

  const customerName  = q('#customer_name');
  const customerEmail = q('#customer_email');
  const customerEmailConfirmation = q('#customer_email_confirmation');
  const customerPhone = q('#customer_phone');

  // Flags (optional hidden inputs rendered by PHP)
  const ignoreOffDaysInput      = q('input[name="ignore_off_days"]');
  const ignoreAvailabilityInput = q('input[name="ignore_availability"]');

  const flagsQuery = () => {
    let s = '';
    if (ignoreOffDaysInput?.value === '1') s += '&ignore_off_days=1';
    if (ignoreAvailabilityInput?.value === '1') s += '&ignore_availability=1';
    return s;
  };

  let flatpickrInstance;
  let debounceTimer;

  // --- Helpers --------------------------------------------------------------

  // Get current value for a logical field, trying select first, then hidden input
  const getValue = (field) => {
    const sel = q(`#${field}-select`);
    if (sel) return sel.value || '';
    const hid = q(`input[name="${field}"]`);
    return hid ? (hid.value || '') : '';
  };

  const norm = (s) =>
    (s ?? '')
      .normalize('NFKC')
      .replace(/[\u200E\u200F\u202A-\u202E]/g, '') // strip bidi marks if any
      .replace(/\u00A0/g, ' ') // NBSP -> space
      .trim()
      .toLowerCase();

  const validateField = (id, message) => {
    const input = q('#' + id);
    const errorSpan = q('#' + id + '-error');
    if (!input || !errorSpan) return true;
    if (input.value.trim() === '') {
      errorSpan.textContent = message;
      return false;
    }
    errorSpan.textContent = '';
    return true;
  };

  const validateEmailMatch = () => {
    const err = q('#customer_email_confirmation-error');
    if (err) err.textContent = '';
    const e1 = norm(customerEmail?.value);
    const e2 = norm(customerEmailConfirmation?.value);
    if (!e1 || !e2) return true; // required checks handle empties
    if (e1 !== e2) {
      if (err) err.textContent = 'Emails do not match.';
      return false;
    }
    return true;
  };

  const addValidationListeners = () => {
    customerName?.addEventListener('blur', () =>
      validateField('customer_name', 'Please enter your name.')
    );
    customerEmail?.addEventListener('blur', () =>
      validateField('customer_email', 'Please enter your email.')
    );
    customerEmailConfirmation?.addEventListener('blur', validateEmailMatch);
    customerPhone?.addEventListener('blur', () =>
      validateField('customer_phone', 'Please enter your phone number.')
    );
  };

  const resetDownstream = () => {
    if (flatpickrInstance) flatpickrInstance.set('enable', []);
    if (timeSlotsDiv) timeSlotsDiv.innerHTML = '';
  };

  const debounce = (fn, delay) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(fn, delay);
  };

  // --- Calendar + Slots -----------------------------------------------------

  const updateCalendar = () => {
    const locationId = getValue('location_id');
    const serviceId  = getValue('service_id');
    const employeeId = getValue('employee_id');

    if (!(locationId && serviceId && employeeId && flatpickrInstance)) return;

    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        `action=medsol_get_available_dates&nonce=${encodeURIComponent(nonce)}` +
        `&location_id=${encodeURIComponent(locationId)}` +
        `&service_id=${encodeURIComponent(serviceId)}` +
        `&employee_id=${encodeURIComponent(employeeId)}` +
        flagsQuery()
    })
    .then(r => r.json())
    .then(data => {
      const enableDates = data?.success && Array.isArray(data.data) ? data.data : [];
      flatpickrInstance.set('enable', enableDates);
    })
    .catch(() => {});
  };

  const loadTimeSlots = (date) => {
    const locationId = getValue('location_id');
    const serviceId  = getValue('service_id');
    const employeeId = getValue('employee_id');
    if (!(locationId && serviceId && employeeId && date)) return;

    if (timeSlotsDiv) timeSlotsDiv.textContent = 'Loading...';

    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:
        `action=medsol_get_time_slots&nonce=${encodeURIComponent(nonce)}` +
        `&location_id=${encodeURIComponent(locationId)}` +
        `&date=${encodeURIComponent(date)}` +
        `&service_id=${encodeURIComponent(serviceId)}` +
        `&employee_id=${encodeURIComponent(employeeId)}` +
        flagsQuery()
    })
    .then(r => r.json())
    .then(data => {
      if (!timeSlotsDiv) return;
      timeSlotsDiv.innerHTML = '';
      const slots = data?.success && Array.isArray(data.data) ? data.data : [];
      if (!slots.length) {
        timeSlotsDiv.textContent = 'No available slots.';
        return;
      }
      slots.forEach(slot => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = `${slot.start} - ${slot.end} (${slot.capacity === 0 ? '0' : slot.capacity})`;
        btn.dataset.time = slot.start; // duration comes from server-side, not trusted from client
        btn.addEventListener('click', (e) => {
          form.querySelectorAll('#time-slots button').forEach(b => b.classList.remove('selected'));
          e.currentTarget.classList.add('selected');
        });
        timeSlotsDiv.appendChild(btn);
      });
    })
    .catch(() => { if (timeSlotsDiv) timeSlotsDiv.textContent = 'Error loading slots.'; });
  };

  const onSelectionChange = () => {
    resetDownstream();
    debounce(updateCalendar, 250);
  };

  const onDateChange = (selectedDates, dateStr) => {
    if (selectedDateInput) selectedDateInput.value = dateStr;
    loadTimeSlots(dateStr);
  };

  // --- Init Flatpickr safely ------------------------------------------------
  if (!calendarContainer) return;

  flatpickrInstance = flatpickr(calendarContainer, {
    enable: [],
    dateFormat: 'Y-m-d',
    minDate: 'today',
    onChange: onDateChange,
    inline: true,
    allowInput: false,
    disableMobile: false
  });

  // --- Listeners only where elements exist ---------------------------------
  locationSelect?.addEventListener('change', onSelectionChange);
  serviceSelect?.addEventListener('change', onSelectionChange);
  employeeSelect?.addEventListener('change', onSelectionChange);
  addValidationListeners();

  // If service/employee are hidden (preselected), initialize calendar once
  if (!serviceSelect || !employeeSelect) {
    updateCalendar();
  }

  // --- Submit ---------------------------------------------------------------
  const timeErr = q('#time-error');
  const emailConfirmErr = q('#customer_email_confirmation-error');

  submitBtn?.addEventListener('click', () => {
    // Clear old messages
    timeErr && (timeErr.textContent = '');
    emailConfirmErr && (emailConfirmErr.textContent = '');
    if (messageDiv) messageDiv.textContent = '';

    const selectedSlot = timeSlotsDiv?.querySelector('button.selected');
    if (!selectedSlot) {
      timeErr && (timeErr.textContent = 'Please select a time.');
      return;
    }

    // Normalize emails before submission
    if (customerEmail) customerEmail.value = norm(customerEmail.value);
    if (customerEmailConfirmation) customerEmailConfirmation.value = norm(customerEmailConfirmation.value);

    // Required field checks
    const okName  = validateField('customer_name',  'Please enter your name.');
    const okEmail = validateField('customer_email', 'Please enter your email.');
    const okPhone = validateField('customer_phone','Please enter your phone number.');
    const okMatch = validateEmailMatch();
    if (!(okName && okEmail && okPhone && okMatch)) return;

    const formData = new FormData(form);
    // Ensure date/time are set from the UI
    if (selectedDateInput) formData.set('date', selectedDateInput.value || '');
    formData.set('time', selectedSlot.dataset.time || '');

    // Do NOT append duration; server derives it from service_id
    const params = new URLSearchParams(formData);
    params.set('action', 'medsol_submit_booking');
    params.set('nonce', nonce);

    if (messageDiv) messageDiv.textContent = 'Submitting...';

    fetch(ajaxUrl, { method: 'POST', body: params })
      .then(r => r.json())
      .then(data => {
        if (data?.success) {
          if (messageDiv) messageDiv.textContent = data.data || 'Booked.';
          form.reset();
          if (timeSlotsDiv) timeSlotsDiv.innerHTML = '';
          if (selectedDateInput) selectedDateInput.value = '';
          resetDownstream();
          // If using hidden preselected fields, reapply their values to Form after reset (optional)
          serviceHidden && (serviceHidden.value = serviceHidden.value); // no-op to show intent
          employeeHidden && (employeeHidden.value = employeeHidden.value);
          locationHidden && (locationHidden.value = locationHidden.value);
        } else {
          if (messageDiv) messageDiv.textContent = data?.data || 'Failed to book appointment.';
        }
      })
      .catch(() => { if (messageDiv) messageDiv.textContent = 'Network error.'; });
  });
});
