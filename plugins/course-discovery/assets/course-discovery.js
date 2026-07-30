/**
 * Course Discovery -- accessible combobox enhancement.
 *
 * Upgrades each <select multiple> filter into a keyboard-operable ARIA
 * combobox. The native <select> stays in the DOM, visually hidden, as the
 * single source of truth for what is selected, so the plain
 * <form method="get"> underneath keeps working exactly as it does with
 * JavaScript disabled. If this file fails to load or throws, the unenhanced
 * multi-select still filters courses on submit.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-cd-root]');
	if (!root) {
		return;
	}

	var config = window.cdDiscoveryConfig || {};

	// ---------------------------------------------------------------------
	// ARIA combobox: upgrades each <select multiple> in place. The select
	// stays in the DOM, visually hidden, as the single source of truth for
	// what is selected -- every keyboard/mouse interaction below mutates it
	// and dispatches 'change' on it, so the form submits exactly the same
	// values it would have without JavaScript -- the enhanced widget's
	// state can never diverge from the no-JS state.
	// ---------------------------------------------------------------------
	var comboboxes = [];

	root.querySelectorAll('select[multiple]').forEach(function (select) {
		comboboxes.push(enhanceCombobox(select));
	});

	// One shared listener for every combobox on the page,
	// rather than one document-level listener per instance (as many as
	// there are <select multiple> filters). Each still closes independently
	// -- only an open combobox whose wrapper does not contain the click
	// target closes -- this only shares the listener registration itself.
	document.addEventListener('click', function (event) {
		comboboxes.forEach(function (cb) {
			if (cb.open && !cb.wrapper.contains(event.target)) {
				closeCombobox(cb);
			}
		});
	});

	function enhanceCombobox(select) {
		var nativeId = select.id;
		var listboxId = nativeId + '-listbox';
		// FormRenderer gives the <label> this id specifically so
		// it can be referenced here -- see that method's own docblock for
		// why a <button>'s accessible name cannot rely on <label for> alone.
		var labelId = nativeId + '-label';

		select.id = nativeId + '-native';
		select.tabIndex = -1;
		select.setAttribute('aria-hidden', 'true');
		select.classList.add('cd-visually-hidden');

		var wrapper = document.createElement('div');
		wrapper.className = 'cd-combobox';
		select.parentNode.insertBefore(wrapper, select);
		wrapper.appendChild(select);

		// The <label for="..."> already on the page targeted the select's
		// original id; moving that id onto the trigger keeps the existing
		// label correctly associated with the new focusable control (e.g.
		// for click-to-focus). The accessible NAME, though, is set
		// explicitly below via aria-labelledby -- see FormRenderer's
		// docblock -- rather than left to that association alone.
		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.id = nativeId;
		trigger.className = 'cd-combobox-trigger';
		trigger.setAttribute('role', 'combobox');
		trigger.setAttribute('aria-haspopup', 'listbox');
		trigger.setAttribute('aria-expanded', 'false');
		trigger.setAttribute('aria-controls', listboxId);
		// References the label's text AND the trigger's own content (its
		// selected-value summary, kept current by updateTriggerLabel()), so
		// the announced name is "<field label>, <current value>" -- e.g.
		// "Location, London" -- rather than either alone.
		trigger.setAttribute('aria-labelledby', labelId + ' ' + nativeId);
		wrapper.appendChild(trigger);

		var listbox = document.createElement('ul');
		listbox.id = listboxId;
		listbox.className = 'cd-combobox-listbox';
		listbox.setAttribute('role', 'listbox');
		listbox.setAttribute('aria-multiselectable', 'true');
		listbox.hidden = true;
		wrapper.appendChild(listbox);

		var cb = {
			select: select,
			trigger: trigger,
			listbox: listbox,
			wrapper: wrapper,
			options: [],
			open: false,
			active: -1,
		};

		Array.prototype.forEach.call(select.options, function (option, index) {
			var li = document.createElement('li');
			li.id = listboxId + '-option-' + index;
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', option.selected ? 'true' : 'false');
			li.textContent = option.textContent;
			listbox.appendChild(li);
			cb.options.push({ option: option, li: li });

			li.addEventListener('click', function () {
				setActive(cb, index);
				toggleOption(cb, index);
			});
		});

		updateTriggerLabel(cb);

		trigger.addEventListener('click', function () {
			cb.open ? closeCombobox(cb) : openCombobox(cb);
		});

		trigger.addEventListener('keydown', function (event) {
			handleKeydown(cb, event);
		});

		return cb;
	}

	function openCombobox(cb) {
		cb.open = true;
		cb.listbox.hidden = false;
		cb.trigger.setAttribute('aria-expanded', 'true');

		var firstSelected = cb.options.findIndex(function (o) {
			return o.option.selected;
		});
		setActive(cb, firstSelected > -1 ? firstSelected : 0);
	}

	function closeCombobox(cb, refocus) {
		cb.open = false;
		cb.listbox.hidden = true;
		cb.trigger.setAttribute('aria-expanded', 'false');
		cb.trigger.removeAttribute('aria-activedescendant');
		cb.active = -1;

		if (refocus) {
			cb.trigger.focus();
		}
	}

	function setActive(cb, index) {
		if (index < 0 || index >= cb.options.length) {
			return;
		}

		if (cb.active > -1) {
			cb.options[cb.active].li.classList.remove('is-active');
		}

		cb.active = index;
		var li = cb.options[index].li;
		li.classList.add('is-active');
		li.scrollIntoView({ block: 'nearest' });
		cb.trigger.setAttribute('aria-activedescendant', li.id);
	}

	/** Flips one option's selected state and syncs the underlying <select>. */
	function toggleOption(cb, index) {
		var entry = cb.options[index];
		var selected = entry.li.getAttribute('aria-selected') !== 'true';

		entry.li.setAttribute('aria-selected', selected ? 'true' : 'false');
		entry.option.selected = selected;
		updateTriggerLabel(cb);

		// Bubbles to the form's 'change' listener, which fires the search --
		cb.select.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function updateTriggerLabel(cb) {
		var chosen = cb.options.filter(function (o) {
			return o.option.selected;
		});

		cb.trigger.textContent = chosen.length
			? chosen.map(function (o) { return o.option.textContent; }).join(', ')
			: cdDiscoveryPlaceholder();
	}

	function cdDiscoveryPlaceholder() {
		return config.anyLabel || 'Any';
	}

	function handleKeydown(cb, event) {
		switch (event.key) {
			case 'ArrowDown':
				event.preventDefault();
				if (!cb.open) {
					openCombobox(cb);
				} else {
					setActive(cb, cb.active + 1);
				}
				break;
			case 'ArrowUp':
				event.preventDefault();
				if (!cb.open) {
					openCombobox(cb);
				} else {
					setActive(cb, cb.active - 1);
				}
				break;
			case 'Home':
				if (cb.open) {
					event.preventDefault();
					setActive(cb, 0);
				}
				break;
			case 'End':
				if (cb.open) {
					event.preventDefault();
					setActive(cb, cb.options.length - 1);
				}
				break;
			case 'Enter':
			case ' ':
				event.preventDefault();
				if (!cb.open) {
					openCombobox(cb);
				} else if (cb.active > -1) {
					toggleOption(cb, cb.active);
				}
				break;
			case 'Escape':
				if (cb.open) {
					event.preventDefault();
					closeCombobox(cb, true);
				}
				break;
			default:
				if (cb.open && event.key.length === 1 && /[a-z0-9]/i.test(event.key)) {
					typeAhead(cb, event.key);
				}
		}
	}

	/** Jumps to the next option (wrapping) whose label starts with `char`. */
	function typeAhead(cb, char) {
		var lower = char.toLowerCase();
		var count = cb.options.length;

		for (var offset = 1; offset <= count; offset++) {
			var index = (cb.active + offset) % count;
			var label = cb.options[index].option.textContent.trim().toLowerCase();

			if (label.indexOf(lower) === 0) {
				setActive(cb, index);
				return;
			}
		}
	}

	// ---------------------------------------------------------------------
	// Sort auto-submit.
	//
	// The listener is scoped to the sort <select> itself, NEVER to the form.
	// A form-level 'change' listener would also fire for every checkbox and
	// for every combobox option toggle -- toggleOption() dispatches 'change'
	// on the native <select> on each keypress -- reloading the page in the
	// middle of a multi-select. With JavaScript off, the select is applied by
	// the Search button like any other field.
	// ---------------------------------------------------------------------
	var sort = root.querySelector('[data-cd-sort]');

	if (sort && sort.form) {
		sort.addEventListener('change', function () {
			if (typeof sort.form.requestSubmit === 'function') {
				sort.form.requestSubmit();
			} else {
				sort.form.submit();
			}
		});
	}

	// ---------------------------------------------------------------------
	// Collapse the filter panel on a narrow viewport, so results are the
	// first thing on screen rather than pushed below five filter groups.
	//
	// This is JavaScript rather than a media query because CSS cannot
	// toggle the [open] attribute. It is also why the panel is rendered
	// OPEN: with this file absent or broken, a phone visitor sees expanded
	// filters -- the previous behaviour -- rather than a panel they cannot
	// open.
	// ---------------------------------------------------------------------
	var filters = root.querySelector('.cd-filters');

	if (filters && typeof window.matchMedia === 'function') {
		var narrow = window.matchMedia('(max-width: 48rem)');

		if (narrow.matches) {
			filters.removeAttribute('open');
		}
	}
})();
