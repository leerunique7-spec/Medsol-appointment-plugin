document.addEventListener('DOMContentLoaded', () => {
	const nonce = medsolAppointments.nonce;
	const ajaxUrl = medsolAppointments.ajax_url;

	// Function to open off-canvas with content
	const openModal = (html) => {
		const modal = document.getElementById('medsol-modal');
		modal.innerHTML = html;
		modal.style.display = 'block';
		document.body.classList.add('medsol-off-canvas-open');

		// Tab switching
		const tabs = modal.querySelectorAll('.nav-tab');
		tabs.forEach(tab => {
			tab.addEventListener('click', (e) => {
				e.preventDefault();
				tabs.forEach(t => t.classList.remove('nav-tab-active'));
				tab.classList.add('nav-tab-active');
				modal.querySelectorAll('.tab-content').forEach(content => content.style.display = 'none');
				document.querySelector(tab.getAttribute('href')).style.display = 'block';
			});
		});

		// Add day off
		const addDayOffBtn = modal.querySelector('.add-day-off');
		if (addDayOffBtn) {
			let dayIndex = modal.querySelectorAll('.day-off-row').length; // Start index from existing rows
			addDayOffBtn.addEventListener('click', () => {
				const list = modal.querySelector('.days-off-list');
				const row = document.createElement('div');
				row.classList.add('day-off-row');
				row.innerHTML = `
					<input type="text" name="days_off[${dayIndex}][reason]" placeholder="Reason">
					<input type="date" name="days_off[${dayIndex}][start_date]">
					<input type="date" name="days_off[${dayIndex}][end_date]">
					<button type="button" class="button remove-day-off">Remove</button>
				`;
				list.appendChild(row);
				dayIndex++;
				attachRemoveListeners();
			});
		}

		// Remove day off (using header text for entity detection + debug logs)
		const attachRemoveListeners = () => {
			modal.addEventListener('click', (e) => {
				if (e.target.classList.contains('remove-day-off')) {
					console.log('Remove button clicked'); // Debug: Confirm click detected
					const row = e.target.closest('.day-off-row');
					const dayOffId = e.target.dataset.dayOffId;
					if (dayOffId) {
						// Determine entity from header text (more reliable)
						const header = modal.querySelector('.medsol-off-canvas-header h2');
						if (!header) {
							alert('Error: Modal header not found.');
							return;
						}
						let entity = '';
						const headerText = header.textContent.toLowerCase();
						if (headerText.includes('employee')) {
							entity = 'employee';
						} else if (headerText.includes('location')) {
							entity = 'location';
						} else {
							alert('Error: Unknown modal type from header: ' + headerText);
							return;
						}
						console.log('Entity detected: ' + entity); // Debug: Confirm entity
						fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: `action=medsol_delete_${entity}_day_off&nonce=${nonce}&day_off_id=${dayOffId}`
						}).then(res => {
							console.log('AJAX response status: ' + res.status); // Debug: Response status
							return res.json();
						}).then(data => {
							if (data.success) {
								row.remove();
								console.log('Day off deleted successfully'); // Debug: Success
							} else {
								alert('Failed to delete day off: ' + (data.data || 'Unknown error.'));
								console.error('Delete failed: ', data); // Debug: Error data
							}
						}).catch(err => {
							alert('Network error: ' + err.message);
							console.error('Fetch error: ', err); // Debug: Network error
						});
					} else {
						row.remove();
					}
				}
			});
		};
		attachRemoveListeners();

		// Save button
		const saveBtn = modal.querySelector('.medsol-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', () => {
				const form = modal.querySelector('form');
				if (!form) {
					alert('Error: Form not found in modal');
					return;
				}

				let entity = '';
				if (typeof form.id === 'string' && form.id.startsWith('medsol-') && form.id.endsWith('-form')) {
					entity = form.id.replace('medsol-', '').replace('-form', '');
				} else {
					// Fallback: Infer from active button class (e.g., if modal opened from .add-service)
					const activeBtnClass = document.querySelector('.add-appointment, .add-employee, .add-service, .add-location')?.classList[1]?.replace('add-', '');
					if (activeBtnClass) {
						entity = activeBtnClass;
					} else {
						alert('Error: Invalid form ID - contact developer with console logs.');
						return;
					}
				}

				const formData = new FormData(form);
				const params = new URLSearchParams();
				for (let [key, value] of formData) {
					params.append(key, value);
				}

				params.append('action', `medsol_save_${entity}`);
				params.append('nonce', nonce);

				fetch(ajaxUrl, {
					method: 'POST',
					body: params
				}).then(res => res.json()).then(data => {
					if (data.success) {
						location.reload(); // Reload to update table
					} else {
						alert(data.data || 'Error saving - check required fields or console.');
					}
				}).catch(err => {
					alert('Network error: ' + err.message);
				});
			});
		}

		// Cancel button
		const cancelBtn = modal.querySelector('.medsol-cancel');
		if (cancelBtn) {
			cancelBtn.addEventListener('click', closeModal);
		}
	};

	// Close modal
	const closeModal = () => {
		const modal = document.getElementById('medsol-modal');
		modal.innerHTML = '';
		modal.style.display = 'none';
		document.body.classList.remove('medsol-off-canvas-open');
	};

	// Add/Edit buttons for appointments
	document.querySelectorAll('.add-appointment, .edit-appointment').forEach(btn => {
		btn.addEventListener('click', (e) => {
			const id = e.target.dataset.id || 0;
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: `action=medsol_get_appointment_modal&nonce=${nonce}&id=${id}`
			}).then(res => res.json()).then(data => {
				if (data.success) openModal(data.data.html);
			});
		});
	});

	// Similar for employees
	document.querySelectorAll('.add-employee, .edit-employee').forEach(btn => {
		btn.addEventListener('click', (e) => {
			const id = e.target.dataset.id || 0;
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: `action=medsol_get_employee_modal&nonce=${nonce}&id=${id}`
			}).then(res => res.json()).then(data => {
				if (data.success) openModal(data.data.html);
			});
		});
	});

	// For services
	document.querySelectorAll('.add-service, .edit-service').forEach(btn => {
		btn.addEventListener('click', (e) => {
			const id = e.target.dataset.id || 0;
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: `action=medsol_get_service_modal&nonce=${nonce}&id=${id}`
			}).then(res => res.json()).then(data => {
				if (data.success) openModal(data.data.html);
			});
		});
	});

	// For locations
	document.querySelectorAll('.add-location, .edit-location').forEach(btn => {
		btn.addEventListener('click', (e) => {
			const id = e.target.dataset.id || 0;
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: `action=medsol_get_location_modal&nonce=${nonce}&id=${id}`
			}).then(res => res.json()).then(data => {
				if (data.success) openModal(data.data.html);
			});
		});
	});
});

// ─────────────────────────────────────────────────────────────
// Notifications module (moved from page-notifications.php)
document.addEventListener('DOMContentLoaded', () => {
	const root = document.querySelector('.medsol-notifications-wrap');
	if (!root) return;

	const $  = (sel, ctx = document) => ctx.querySelector(sel);
	const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

	// ▼▼ Disable unsaved-changes notifications (requested)
	const DISABLE_UNSAVED_WARNING = true;

	// Read localized strings from hidden inputs (fallback to English)
	const L = {
		unsaved: $('#medsol-notif-l10n-unsaved')?.value || 'Unsaved changes',
		leave: $('#medsol-notif-l10n-leave')?.value || 'You have unsaved changes. Leave without saving?',
		testSent: $('#medsol-notif-l10n-test-sent')?.value || 'Test email sent.',
		testFailed: $('#medsol-notif-l10n-test-failed')?.value || 'Failed to send test email. Check mail configuration.',
		unexpected: $('#medsol-notif-l10n-unexpected')?.value || 'Unexpected error.'
	};

	// Read data blobs from hidden textareas
	let placeholders = {};
	let templates = {};
	try {
		const ph = $('#medsol-notif-placeholders')?.textContent || '{}';
		placeholders = JSON.parse(ph);
	} catch (e) { placeholders = {}; }
	try {
		const tp = $('#medsol-notif-templates')?.textContent || '{}';
		templates = JSON.parse(tp);
	} catch (e) { templates = {}; }

	// Persist + restore last-used channel & audience
	const LS_KEY_CH = 'medsolNotif.channel';
	const LS_KEY_AU = 'medsolNotif.audience';

	// Channel tabs
	$$('.medsol-channel-tabs .nav-tab', root).forEach(tab => {
		tab.addEventListener('click', e => {
			e.preventDefault();
			$$('.medsol-channel-tabs .nav-tab', root).forEach(t => t.classList.remove('nav-tab-active'));
			tab.classList.add('nav-tab-active');

			const target = tab.getAttribute('href');
			$$('.medsol-channel-panel', root).forEach(p => p.style.display = 'none');
			const panel = $(target, root);
			if (panel) panel.style.display = 'block';

			try { localStorage.setItem(LS_KEY_CH, target); } catch (e) {}
		});
	});

	// Audience subtabs
	$$('.medsol-subtab', root).forEach(sub => {
		sub.addEventListener('click', e => {
			e.preventDefault();
			const wrap = sub.closest('.medsol-channel-panel');
			if (!wrap) return;
			$$('.medsol-subtab', wrap).forEach(t => t.classList.remove('is-active'));
			sub.classList.add('is-active');

			const target = sub.getAttribute('href');
			$$('.medsol-audience-grid', wrap).forEach(p => p.style.display = 'none');
			const grid = $(target, wrap);
			if (grid) grid.style.display = 'grid';

			try { localStorage.setItem(LS_KEY_AU, target); } catch (e) {}
		});
	});

	// Restore persisted tabs
	try {
		const savedCh = localStorage.getItem(LS_KEY_CH);
		if (savedCh && $(savedCh, root)) {
			const t = $$('.medsol-channel-tabs .nav-tab', root).find(t => t.getAttribute('href') === savedCh);
			t?.click();
		}
		const savedAu = localStorage.getItem(LS_KEY_AU);
		if (savedAu && $(savedAu, root)) {
			const s = $$('.medsol-subtab', root).find(t => t.getAttribute('href') === savedAu);
			s?.click();
		}
	} catch (e) {}

	// Left rail selection (which editor is visible)
	$$('.medsol-left-rail', root).forEach(rail => {
		rail.addEventListener('click', e => {
			const li = e.target.closest('.medsol-template-item');
			if (!li) return;
			const tpl = li.getAttribute('data-template');

			const grid = rail.closest('.medsol-audience-grid');
			if (!grid) return;

			$$('.medsol-template-item', rail).forEach(i => i.classList.remove('is-active'));
			li.classList.add('is-active');

			$$('.medsol-template-editor', grid).forEach(ed => {
				if (!ed) return;
				ed.style.display = (ed.getAttribute('data-template') === tpl ? 'block' : 'none');
			});
		});

		// Activate first visible item by default
		const first = rail.querySelector('.medsol-template-item');
		const grid  = rail.closest('.medsol-audience-grid');
		if (first && grid) {
			first.classList.add('is-active');
			const tpl = first.getAttribute('data-template');
			$$('.medsol-template-editor', grid).forEach(ed => {
				if (!ed) return;
				ed.style.display = (ed.getAttribute('data-template') === tpl ? 'block' : 'none');
			});
		}
	});

	// Drawer open/close
	const drawer = $('#medsol-drawer', root);
	function openDrawer(btn){
		if (!drawer) return;
		drawer.classList.add('is-open');
		drawer.setAttribute('aria-hidden','false');
		if (btn) btn.setAttribute('aria-expanded','true');
		drawer.focus();
	}
	function closeDrawer(){
		if (!drawer) return;
		drawer.classList.remove('is-open');
		drawer.setAttribute('aria-hidden','true');
		$$('.medsol-show-placeholders', root).forEach(b => b.setAttribute('aria-expanded','false'));
	}
	$$('.medsol-show-placeholders', root).forEach(btn => btn.addEventListener('click', () => openDrawer(btn)));
	$('.medsol-drawer-close', root)?.addEventListener('click', closeDrawer);
	document.addEventListener('keydown', e => {
		if (e.key === 'Escape' && drawer?.classList.contains('is-open')) closeDrawer();
	});

	// Drawer search
	const search = $('.medsol-search', root);
	if (search) {
		search.addEventListener('input', () => {
			const needle = search.value.toLowerCase();
			$$('.medsol-ph-item', root).forEach(li => {
				const code  = (li.getAttribute('data-code') || '').toLowerCase();
				const label = ($('.medsol-ph-label', li)?.textContent || '').toLowerCase();
				li.style.display = (code.includes(needle) || label.includes(needle)) ? '' : 'none';
			});
		});
	}

	// Insert/Copy placeholder actions
	function insertAtCaret(el, text) {
		// TinyMCE active?
		if (window.tinymce && tinymce.get(el.id)) {
			tinymce.get(el.id).execCommand('mceInsertContent', false, text);
			return;
		}
		// Plain textarea
		const start = el.selectionStart, end = el.selectionEnd;
		el.value = el.value.slice(0, start) + text + el.value.slice(end);
		el.dispatchEvent(new Event('input', { bubbles:true }));
		el.focus();
		el.selectionStart = el.selectionEnd = start + text.length;
	}

	$$('.medsol-insert', root).forEach(btn => {
		btn.addEventListener('click', () => {
			const code = btn.getAttribute('data-code') || '';
			const current = document.querySelector('.medsol-template-editor[style*="block"] .medsol-textarea');
			if (current) insertAtCaret(current, code);
		});
	});
	$$('.medsol-copy', root).forEach(btn => {
		btn.addEventListener('click', async () => {
			try { await navigator.clipboard.writeText(btn.getAttribute('data-code') || ''); } catch(e){}
			btn.blur();
		});
	});

	// Dirty state + beforeunload guard
	const form = $('#medsol-notifications-form', root);
	let isDirty = false;
	function markDirty(){
		// ▼▼ honor the flag: do nothing (prevents sticky text + prompts)
		if (DISABLE_UNSAVED_WARNING) return;

		if (isDirty) return;
		isDirty = true;
		const bar = document.querySelector('.medsol-template-editor[style*="block"] .medsol-dirty-indicator');
		if (bar) bar.textContent = L.unsaved;
	}
	if (form) {
		form.addEventListener('input', markDirty);
		form.addEventListener('change', markDirty);
		form.addEventListener('submit', () => { isDirty = false; });
	}
	// Keep the listener, but it will never fire because isDirty stays false
	window.addEventListener('beforeunload', function(e){
		if (!isDirty) return;
		e.preventDefault();
		e.returnValue = '';
	});

	// Guard navigation when dirty (kept but never triggers since isDirty=false)
	function guardNav(handler){
		return function(e){
			if (!isDirty) return handler.call(this, e);
			const ok = window.confirm(L.leave);
			if (!ok) { e.preventDefault(); e.stopImmediatePropagation(); return false; }
			return handler.call(this, e);
		};
	}
	$$('.medsol-channel-tabs .nav-tab', root).forEach(tab => {
		tab.addEventListener('click', guardNav(()=>{}), { capture:true });
	});
	$$('.medsol-subtab', root).forEach(tab => {
		tab.addEventListener('click', guardNav(()=>{}), { capture:true });
	});
	$$('.medsol-left-rail .medsol-template-item', root).forEach(item => {
		item.addEventListener('click', guardNav(()=>{}), { capture:true });
	});

	// Mode switch: Text <-> HTML (lazy TinyMCE)
	function initTinyMCE(textarea){
		const id = textarea.id;
		if (window.tinymce && tinymce.get(id)) return;
		if (!window.wp || !wp.editor) return;
		wp.editor.initialize(id, { tinymce: { wpautop: true, height: 300 }, quicktags: true, mediaButtons: false });
	}
	function destroyTinyMCE(textarea){
		const id = textarea.id;
		if (window.tinymce && tinymce.get(id) && window.wp?.editor) {
			wp.editor.remove(id);
		}
	}
	$$('.medsol-template-editor', root).forEach(editor => {
		editor.addEventListener('change', e => {
			if (!e.target.classList.contains('medsol-mode-switch')) return;
			const mode = e.target.value;
			const ta = editor.querySelector('.medsol-textarea');
			if (!ta) return;
			if (mode === 'html') initTinyMCE(ta);
			else destroyTinyMCE(ta);
		});
	});

	// Test email modal
	const modal = $('#medsol-test-modal', root);
	function openModal(){ if (!modal) return; modal.setAttribute('aria-hidden','false'); $('#medsol-test-to', root)?.focus(); }
	function closeModal(){ if (!modal) return; modal.setAttribute('aria-hidden','true'); const fb = $('#medsol-test-feedback', root); if (fb) fb.style.display='none'; }
	$$('.medsol-test-email', root).forEach(btn => btn.addEventListener('click', () => openModal()));
	$$('.medsol-modal-close', root).forEach(b => b.addEventListener('click', closeModal));
	document.addEventListener('keydown', e => { if(e.key==='Escape' && modal?.getAttribute('aria-hidden')==='false') closeModal(); });

	// Send test email
	const sendBtn = $('#medsol-test-send', root);
	if (sendBtn) {
		sendBtn.addEventListener('click', async () => {
			const to   = ($('#medsol-test-to', root)?.value || '').trim();
			const sel  = ($('#medsol-test-template', root)?.value || '').split('|'); // [recipient|template]
			const recipient = sel[0] || 'customer';
			const template  = sel[1] || 'approved';
			const nonceEl   = $('#medsol-notifications-nonce', root);
			const nonce     = nonceEl ? nonceEl.value : '';
			const fb        = $('#medsol-test-feedback', root);
			if (!fb) return;

			fb.style.display = 'none';

			const formData = new FormData();
			formData.append('action','medsol_notifications_send_test');
			formData.append('nonce', nonce);
			formData.append('send_to', to);
			formData.append('template_key', template);
			formData.append('recipient_key', recipient);

			try{
				const res  = await fetch(window.ajaxurl, { method:'POST', body: formData });
				const data = await res.json();
				fb.className = 'notice ' + (data.success ? 'notice-success' : 'notice-error');
				fb.textContent = data.success ? L.testSent : (data.data?.message || L.testFailed);
				fb.style.display = 'block';
			} catch(err){
				fb.className = 'notice notice-error';
				fb.textContent = L.unexpected;
				fb.style.display = 'block';
			}
		});
	}
});
